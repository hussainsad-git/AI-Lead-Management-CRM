<?php

namespace App\Services;

use App\Enums\StatusSemantic;
use App\Exceptions\DomainRuleException;
use App\Models\CallOutcome;
use App\Models\CallTask;
use App\Models\Lead;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Calls and follow-ups. Durations are only stored when a user enters them; nothing here
 * claims to record calls or read telephony data.
 */
class FollowUpService
{
    public function __construct(private ActivityLogger $activity, private LeadStatusService $statuses) {}

    public function schedule(Lead $lead, array $data): CallTask
    {
        $lead->loadMissing('contact', 'status');
        if ($lead->contact->do_not_contact) {
            throw new DomainRuleException('This contact has opted out; follow-ups cannot be scheduled.', 'contact_suppressed');
        }
        if ($lead->status->category !== 'open') {
            throw new DomainRuleException('Reopen the lead before scheduling follow-ups.', 'lead_closed');
        }
        if ($lead->archived_at) {
            throw new DomainRuleException('Archived leads cannot receive follow-ups.', 'lead_archived');
        }

        $task = CallTask::create([
            'lead_id' => $lead->id,
            'assigned_user_id' => $data['assigned_user_id'] ?? $lead->assigned_user_id ?? auth()->id(),
            'created_by' => auth()->id(),
            'scheduled_at' => $data['scheduled_at'],
            'priority' => $data['priority'] ?? $lead->priority,
            'purpose' => $data['purpose'] ?? 'Follow-up call',
            'attempt_number' => $lead->contact_attempts + 1,
            'notes' => $data['notes'] ?? null,
        ]);
        $this->activity->forLead($lead, 'follow_up_scheduled', $task->purpose, ['task_id' => $task->id, 'scheduled_at' => $task->scheduled_at->toIso8601ZuluString()]);
        $this->refreshNextFollowUp($lead);

        return $task->refresh();
    }

    /** Record the outcome of a scheduled call, or of an ad-hoc call when $task is null. */
    public function logCall(Lead $lead, ?CallTask $task, array $data): CallTask
    {
        $outcome = CallOutcome::where('is_active', true)->findOrFail($data['call_outcome_id']);
        if ($outcome->creates_callback && empty($data['callback_at'])) {
            throw new DomainRuleException('Choose a callback date and time.', 'callback_time_required', 422);
        }
        if ($task && $task->status !== 'pending' && $task->status !== 'missed') {
            throw new DomainRuleException('This follow-up is already '.$task->status.'.', 'task_not_open', 409);
        }

        return DB::transaction(function () use ($lead, $task, $data, $outcome) {
            $now = now();
            $task ??= new CallTask([
                'lead_id' => $lead->id, 'assigned_user_id' => auth()->id(), 'created_by' => auth()->id(),
                'scheduled_at' => $now, 'priority' => $lead->priority, 'purpose' => $data['purpose'] ?? 'Call',
                'attempt_number' => $lead->contact_attempts + 1,
            ]);
            $task->fill([
                'status' => 'completed', 'call_outcome_id' => $outcome->id,
                'duration_seconds' => isset($data['duration_minutes']) && $data['duration_minutes'] !== '' ? (int) round($data['duration_minutes'] * 60) : null,
                'notes' => $data['notes'] ?? $task->notes, 'next_action' => $data['next_action'] ?? null,
                'completed_at' => $now, 'completed_by' => auth()->id(),
            ])->save();

            $lead->forceFill([
                'contact_attempts' => $lead->contact_attempts + 1,
                'unanswered_attempts' => $outcome->is_connected ? 0 : $lead->unanswered_attempts + 1,
                'first_contacted_at' => $lead->first_contacted_at ?? $now,
                'last_contacted_at' => $now,
                'first_responded_at' => $outcome->is_connected ? ($lead->first_responded_at ?? $now) : $lead->first_responded_at,
            ])->save();

            $this->activity->forLead($lead, 'call_logged', $data['notes'] ?? null, [
                'task_id' => $task->id, 'outcome' => $outcome->name, 'outcome_key' => $outcome->key,
                'connected' => $outcome->is_connected, 'attempt' => $task->attempt_number,
                'duration_seconds' => $task->duration_seconds, 'duration_source' => $data['duration_source'] ?? ($task->duration_seconds ? 'entered' : null),
                'telephony_call_id' => $data['telephony_call_id'] ?? null,
            ]);

            if ($outcome->advances_to_semantic) {
                $target = StatusSemantic::from($outcome->advances_to_semantic);
                $reason = $target->category() === 'open' ? null : 'Call outcome: '.$outcome->name;
                $this->statuses->advance($lead->fresh(['status', 'contact']), $target, $reason, 'call_outcome');
                $lead->refresh();
            }

            if ($outcome->creates_callback) {
                $this->schedule($lead->fresh(['contact', 'status']), [
                    'scheduled_at' => $data['callback_at'], 'purpose' => 'Requested callback', 'priority' => 'high',
                    'assigned_user_id' => $task->assigned_user_id,
                ]);
            } elseif (! empty($data['next_follow_up_at']) && $lead->fresh('status')->status->category === 'open' && ! $lead->contact->do_not_contact) {
                $this->schedule($lead->fresh(['contact', 'status']), [
                    'scheduled_at' => $data['next_follow_up_at'], 'purpose' => $data['next_action'] ?: 'Follow-up call',
                    'assigned_user_id' => $task->assigned_user_id,
                ]);
            }
            $this->refreshNextFollowUp($lead);
            app(\App\Services\Automation\AutomationEngine::class)->fire('call.logged', $lead, 'task:'.$task->id, ['outcome_key' => $outcome->key, 'task_id' => $task->id]);

            return $task->fresh(['outcome', 'lead']);
        });
    }

    public function reschedule(CallTask $task, CarbonInterface|string $at): CallTask
    {
        if (! in_array($task->status, ['pending', 'missed'], true)) {
            throw new DomainRuleException('Only open follow-ups can be rescheduled.', 'task_not_open', 409);
        }
        $old = $task->scheduled_at;
        $task->forceFill(['scheduled_at' => $at, 'status' => 'pending'])->save();
        $this->activity->forLead($task->lead, 'follow_up_rescheduled', null, ['task_id' => $task->id, 'from' => $old->toIso8601ZuluString(), 'to' => $task->scheduled_at->toIso8601ZuluString()]);
        $this->refreshNextFollowUp($task->lead);

        return $task;
    }

    public function cancel(CallTask $task, string $reason): CallTask
    {
        if (! in_array($task->status, ['pending', 'missed'], true)) {
            throw new DomainRuleException('Only open follow-ups can be cancelled.', 'task_not_open', 409);
        }
        $task->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();
        $this->activity->forLead($task->lead, 'follow_up_cancelled', $reason, ['task_id' => $task->id]);
        $this->refreshNextFollowUp($task->lead);

        return $task;
    }

    /** Scheduler: pending tasks far past due become "missed" (still actionable). */
    public function markMissed(int $afterHours): int
    {
        return CallTask::where('status', 'pending')->where('scheduled_at', '<', now()->subHours($afterHours))
            ->update(['status' => 'missed', 'updated_at' => now()]);
    }

    public function refreshNextFollowUp(Lead $lead): void
    {
        $next = CallTask::where('lead_id', $lead->id)->whereIn('status', ['pending', 'missed'])->min('scheduled_at');
        $lead->forceFill(['next_follow_up_at' => $next])->saveQuietly();
    }
}
