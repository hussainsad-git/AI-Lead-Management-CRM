<?php

namespace App\Services\Automation;

use App\Enums\StatusSemantic;
use App\Exceptions\DomainRuleException;
use App\Jobs\RunAutomation;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\CallTask;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\AssignmentRouter;
use App\Services\AssignmentService;
use App\Services\FollowUpService;
use App\Services\LeadService;
use App\Services\LeadStatusService;
use App\Services\NotificationService;
use App\Services\WhatsApp\MessagingService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Trigger → conditions → actions. Idempotent: an (rule, event) pair is recorded before actions run,
 * so retries and duplicate events never repeat actions. Loop guard: events raised by automation
 * actions carry a depth; anything deeper than automation.max_depth is skipped and logged.
 */
class AutomationEngine
{
    private static int $depth = 0;

    public function __construct(private ConditionEvaluator $conditions, private CurrentOrganization $org) {}

    /** Called by domain services. Queued after the surrounding transaction commits. */
    public function fire(string $trigger, Lead $lead, string $eventId, array $ctx = []): void
    {
        $orgId = $this->org->id();
        if (! $orgId || ! AutomationRule::where('trigger', $trigger)->where('is_active', true)->exists()) {
            return;
        }
        RunAutomation::dispatch($trigger, $lead->id, $orgId, $eventId, $ctx, self::$depth)->afterCommit();
    }

    /** @return array<int, AutomationExecution> */
    public function run(string $trigger, Lead $lead, string $eventId, array $ctx = [], int $depth = 0, ?AutomationRule $only = null): array
    {
        $rules = $only ? collect([$only]) : AutomationRule::where('trigger', $trigger)->where('is_active', true)->orderBy('id')->get();
        $out = [];
        // Every rule for one event sees the same lead state (a snapshot), so an earlier rule's actions
        // can't make a later rule match an event that didn't concern it.
        $lead->refresh();
        if ($lead->archived_at) {
            return [];
        }
        $snapshot = clone $lead;
        foreach ($rules as $rule) {
            if (! $this->conditions->passes($rule->conditions, $snapshot, $ctx)) {
                continue;
            }
            try {
                $exec = AutomationExecution::create([
                    'automation_rule_id' => $rule->id, 'lead_id' => $lead->id, 'trigger' => $trigger,
                    'dedupe_key' => mb_substr($trigger.':'.$eventId, 0, 120), 'status' => 'running', 'depth' => $depth,
                ]);
            } catch (UniqueConstraintViolationException) {
                continue; // already ran for this event
            }
            if ($depth >= config('automation.max_depth')) {
                $exec->update(['status' => 'skipped', 'error' => 'Loop guard: too many chained automations']);
                $out[] = $exec;
                continue;
            }

            $results = [];
            $failed = 0;
            $prev = self::$depth;
            self::$depth = $depth + 1;
            try {
                foreach ((array) $rule->actions as $i => $action) {
                    try {
                        $results[] = ['action' => $action['type'] ?? '?', 'ok' => true, 'detail' => $this->perform($action, $lead->fresh(), $rule, $exec, $ctx)];
                    } catch (Throwable $e) {
                        $failed++;
                        if (! $e instanceof DomainRuleException) {
                            report($e);
                        }
                        $results[] = ['action' => $action['type'] ?? '?', 'ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 200)];
                    }
                }
            } finally {
                self::$depth = $prev;
            }
            $status = $failed === 0 ? 'success' : ($failed === count($results) ? 'failed' : 'partial');
            $exec->update(['status' => $status, 'results' => $results, 'error' => $failed ? 'One or more actions failed' : null]);
            $rule->forceFill(['run_count' => $rule->run_count + 1, 'error_count' => $rule->error_count + ($failed ? 1 : 0), 'last_run_at' => now()])->saveQuietly();
            $out[] = $exec;
        }

        return $out;
    }

    private function perform(array $a, Lead $lead, AutomationRule $rule, AutomationExecution $exec, array $ctx): ?string
    {
        $notify = app(NotificationService::class);
        $msg = fn () => $this->render((string) ($a['message'] ?? $rule->name), $lead);
        // One notification per person per execution (the owner may also be their own escalation target).
        $dedupe = fn ($uid) => 'auto:'.$exec->id.':'.$uid;
        $url = '/leads/'.$lead->id;

        switch ($a['type'] ?? null) {
            case 'assign_round_robin':
                if ($lead->assigned_user_id) {
                    return 'already assigned';
                }
                $user = app(AssignmentRouter::class)->pick(isset($a['team_id']) ? (int) $a['team_id'] : null);
                if (! $user) {
                    throw new DomainRuleException('No available salesperson in the pool.');
                }
                app(AssignmentService::class)->assign($lead, $user->id, 'Automation: '.$rule->name);
                $notify->notify($user->id, 'lead_assigned', 'New lead assigned: '.$lead->contact->displayName(), $rule->name, $url, 'assigned:'.$lead->id.':'.$user->id);

                return 'assigned to '.$user->name;
            case 'assign_user':
                $u = User::query()->inCurrentOrganization()->where('is_active', true)->findOrFail((int) $a['user_id']);
                app(AssignmentService::class)->assign($lead, $u->id, 'Automation: '.$rule->name);
                $notify->notify($u->id, 'lead_assigned', 'New lead assigned: '.$lead->contact->displayName(), $rule->name, $url, 'assigned:'.$lead->id.':'.$u->id);

                return 'assigned to '.$u->name;
            case 'create_task':
                if ($lead->status->category !== 'open' || $lead->contact->do_not_contact) {
                    return 'skipped: lead closed or opted out';
                }
                $task = app(FollowUpService::class)->schedule($lead, [
                    'scheduled_at' => now()->addMinutes(max(0, (int) ($a['in_minutes'] ?? 0))),
                    'purpose' => mb_substr($this->render((string) ($a['purpose'] ?? 'Follow-up call'), $lead), 0, 191),
                    'priority' => $a['priority'] ?? null,
                ]);

                return 'task #'.$task->id;
            case 'notify_owner':
                if (! $lead->assigned_user_id) {
                    return 'no owner';
                }
                $notify->notify($lead->assigned_user_id, 'automation', $msg(), $lead->contact->displayName(), $url, $dedupe($lead->assigned_user_id));

                return 'notified owner';
            case 'notify_manager':
                $ids = array_values(array_diff($notify->escalationTargets($lead->assigned_user_id), [$lead->assigned_user_id]));
                if (! $ids) {
                    return 'no manager besides the owner';
                }
                foreach ($ids as $uid) {
                    $notify->notify($uid, 'escalation', $msg(), $lead->contact->displayName().($lead->assignee ? ' · '.$lead->assignee->name : ''), $url, $dedupe($uid));
                }

                return 'notified '.count($ids);
            case 'notify_users':
                $ids = User::query()->inCurrentOrganization()->whereIn('id', (array) ($a['user_ids'] ?? []))->pluck('id');
                foreach ($ids as $uid) {
                    $notify->notify($uid, 'automation', $msg(), $lead->contact->displayName(), $url, $dedupe($uid));
                }

                return 'notified '.$ids->count();
            case 'set_priority':
                $lead->forceFill(['priority' => $a['priority']])->save();

                return 'priority '.$a['priority'];
            case 'add_tag':
                app(LeadService::class)->syncTags($lead, array_merge($lead->tags()->pluck('name')->all(), [(string) $a['tag']]));

                return 'tag '.$a['tag'];
            case 'move_stage':
                $status = LeadStatus::where('is_active', true)->where('category', 'open')->findOrFail((int) $a['status_id']);
                if ($lead->status->category !== 'open') {
                    return 'skipped: lead closed';
                }
                app(LeadStatusService::class)->transition($lead->load('status', 'contact'), $status, 'Automation: '.$rule->name, 'automation');

                return 'moved to '.$status->name;
            case 'cancel_tasks':
                $n = CallTask::where('lead_id', $lead->id)->whereIn('status', ['pending', 'missed'])->update(['status' => 'cancelled', 'cancel_reason' => 'Automation: '.$rule->name, 'updated_at' => now()]);
                app(FollowUpService::class)->refreshNextFollowUp($lead);

                return "cancelled $n";
            case 'send_whatsapp_template':
                $tpl = MessageTemplate::where('status', 'APPROVED')->findOrFail((int) $a['template_id']);
                $wa = preg_replace('/\D/', '', (string) ($lead->contact->whatsapp_e164 ?: $lead->contact->phone_e164));
                if (! $wa) {
                    throw new DomainRuleException('Lead has no WhatsApp number.');
                }
                $c = Conversation::firstOrCreate(['channel' => 'whatsapp', 'external_id' => $wa], ['contact_id' => $lead->contact_id, 'lead_id' => $lead->id]);
                $params = array_map(fn ($p) => $this->render(trim($p), $lead), array_filter(explode('|', (string) ($a['params'] ?? '')), fn ($p) => trim($p) !== ''));
                $m = app(MessagingService::class)->sendTemplate($c->load('contact', 'lead'), $tpl, $params, null);

                return 'template sent ('.$m->status.')';
            default:
                throw new DomainRuleException('Unknown action.');
        }
    }

    /** Placeholders: {name} {phone} {owner} {interest} {lead_id} */
    private function render(string $s, Lead $lead): string
    {
        $lead->loadMissing('contact', 'assignee');

        return strtr($s, [
            '{name}' => $lead->contact->name ?: $lead->contact->displayName(), '{phone}' => (string) ($lead->contact->phone_e164 ?: $lead->contact->whatsapp_e164),
            '{owner}' => $lead->assignee?->name ?? 'unassigned', '{interest}' => (string) $lead->product_interest, '{lead_id}' => (string) $lead->id,
        ]);
    }

    // ---------------- timed triggers ----------------

    /** Scheduler entry point: evaluates timed rules for the bound organization. */
    public function scan(): int
    {
        $n = 0;
        foreach (AutomationRule::where('is_active', true)->whereIn('trigger', ['lead.uncontacted', 'followup.overdue', 'lead.inactive'])->get() as $rule) {
            $cut = now()->subMinutes(max(1, $rule->delay_minutes));
            $open = fn () => Lead::whereNull('archived_at')->whereHas('status', fn ($q) => $q->where('category', 'open'))
                ->whereHas('contact', fn ($c) => $c->where('do_not_contact', false));
            switch ($rule->trigger) {
                case 'lead.uncontacted':
                    $open()->whereNull('first_contacted_at')->where('created_at', '<=', $cut)->where('created_at', '>=', $cut->copy()->subDays(7))
                        ->limit(500)->get()->each(function ($l) use ($rule, &$n) {
                            $n += count($this->run('lead.uncontacted', $l, 'lead:'.$l->id, [], 0, $rule));
                        });
                    break;
                case 'lead.inactive':
                    $open()->whereNotNull('last_activity_at')->where('last_activity_at', '<=', $cut)->where('last_activity_at', '>=', $cut->copy()->subDays(30))
                        ->limit(500)->get()->each(function ($l) use ($rule, &$n) {
                            $n += count($this->run('lead.inactive', $l, 'lead:'.$l->id.':'.$l->last_activity_at->timestamp, [], 0, $rule));
                        });
                    break;
                case 'followup.overdue':
                    CallTask::where('status', 'pending')->where('scheduled_at', '<=', $cut)->where('scheduled_at', '>=', $cut->copy()->subDays(7))
                        ->with('lead')->limit(500)->get()->each(function ($t) use ($rule, &$n) {
                            if ($t->lead && ! $t->lead->archived_at) {
                                $n += count($this->run('followup.overdue', $t->lead, 'task:'.$t->id, ['task_priority' => $t->priority, 'task_id' => $t->id], 0, $rule));
                            }
                        });
                    break;
            }
        }

        return $n;
    }

    public static function currentDepth(): int
    {
        return self::$depth;
    }
}
