<?php

namespace App\Services\Automation;

use App\Models\LeadStatus;
use App\Models\MessageTemplate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Validates a rule against config/automation.php; every referenced id must belong to the current organization. */
class RuleValidator
{
    public function validate(array $in): array
    {
        $cfg = config('automation');
        $err = [];
        $trigger = (string) ($in['trigger'] ?? '');
        if (! isset($cfg['triggers'][$trigger])) {
            $err['trigger'] = 'Choose a trigger.';
        }
        $timed = ($cfg['triggers'][$trigger]['kind'] ?? null) === 'timed';
        $delay = (int) ($in['delay_minutes'] ?? 0);
        if ($timed && ($delay < 5 || $delay > 43200)) {
            $err['delay_minutes'] = 'Timed triggers need a delay between 5 minutes and 30 days.';
        }

        $conds = [];
        foreach (array_values((array) ($in['conditions']['rules'] ?? [])) as $i => $c) {
            $field = $cfg['fields'][$c['field'] ?? ''] ?? null;
            if (! $field) {
                $err["conditions.$i"] = 'Unknown field.';
                continue;
            }
            $type = $field[1];
            $op = (string) ($c['op'] ?? '');
            if (! isset($cfg['operators'][$type][$op])) {
                $err["conditions.$i"] = 'Invalid operator for '.$field[0].'.';
                continue;
            }
            $v = $c['value'] ?? null;
            $v = match ($type) {
                'enum' => array_values(array_filter(array_map('strval', (array) $v), fn ($x) => $x !== '')),
                'number' => is_numeric($v) ? (float) $v : null,
                'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                default => in_array($op, ['empty', 'not_empty'], true) ? null : mb_substr(trim((string) $v), 0, 120),
            };
            if (($type === 'enum' && ! $v) || ($type === 'number' && $v === null) || ($type === 'text' && $op === 'contains' && $v === '')) {
                $err["conditions.$i"] = 'Enter a value for '.$field[0].'.';
                continue;
            }
            $conds[] = ['field' => $c['field'], 'op' => $op, 'value' => $v];
        }
        if (count($conds) > 10) {
            $err['conditions'] = 'At most 10 conditions.';
        }

        $actions = [];
        foreach (array_values((array) ($in['actions'] ?? [])) as $i => $a) {
            $type = (string) ($a['type'] ?? '');
            if (! isset($cfg['actions'][$type])) {
                $err["actions.$i"] = 'Unknown action.';
                continue;
            }
            try {
                $actions[] = ['type' => $type] + $this->params($type, $a);
            } catch (\InvalidArgumentException $e) {
                $err["actions.$i"] = $e->getMessage();
            }
        }
        if (! $actions && ! isset($err['actions.0'])) {
            $err['actions'] = 'Add at least one action.';
        }
        if (count($actions) > 8) {
            $err['actions'] = 'At most 8 actions.';
        }
        if ($trigger === 'lead.opted_out' && array_intersect(array_column($actions, 'type'), ['create_task', 'send_whatsapp_template'])) {
            $err['actions'] = 'An opt-out rule cannot schedule contact or send messages.';
        }

        if ($err) {
            throw ValidationException::withMessages($err);
        }

        return [
            'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 150) ?: $cfg['triggers'][$trigger]['label'],
            'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 500) ?: null,
            'trigger' => $trigger, 'delay_minutes' => $timed ? $delay : 0,
            'conditions' => ['match' => ($in['conditions']['match'] ?? 'all') === 'any' ? 'any' : 'all', 'rules' => $conds],
            'actions' => $actions,
            'is_active' => filter_var($in['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** https only, no private/loopback hosts (SSRF), no credentials in the URL. */
    private function webhookUrl(string $url): string
    {
        $url = trim($url);
        $p = parse_url($url);
        if (! $p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || strlen($url) > 500) {
            throw new \InvalidArgumentException('Enter an https:// URL.');
        }
        $host = strtolower($p['host']);
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : (gethostbyname($host) !== $host ? gethostbyname($host) : null);
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
            || ($ip && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            throw new \InvalidArgumentException('Webhook URLs must point to a public host.');
        }

        return $url;
    }

    private function params(string $type, array $a): array
    {
        $users = fn () => User::query()->inCurrentOrganization()->where('is_active', true);
        $msg = fn () => ($m = mb_substr(trim((string) ($a['message'] ?? '')), 0, 191)) !== '' ? $m : throw new \InvalidArgumentException('Enter a message.');
        $prio = fn ($req) => in_array($a['priority'] ?? null, ['low', 'normal', 'high', 'urgent'], true) ? $a['priority']
            : ($req ? throw new \InvalidArgumentException('Choose a priority.') : null);

        return match ($type) {
            'assign_round_robin' => ! empty($a['team_id']) ? ['team_id' => (Team::find((int) $a['team_id']) ?? throw new \InvalidArgumentException('Choose a team.'))->id] : [],
            'assign_user' => ['user_id' => ($users()->find((int) ($a['user_id'] ?? 0)) ?? throw new \InvalidArgumentException('Choose an active user.'))->id],
            'create_task' => array_filter([
                'purpose' => ($p = mb_substr(trim((string) ($a['purpose'] ?? '')), 0, 191)) !== '' ? $p : 'Follow-up call',
                'in_minutes' => max(0, min(43200, (int) ($a['in_minutes'] ?? 0))), 'priority' => $prio(false),
            ], fn ($v) => $v !== null),
            'notify_owner', 'notify_manager' => ['message' => $msg()],
            'notify_users' => ['user_ids' => ($ids = $users()->whereIn('id', array_map('intval', (array) ($a['user_ids'] ?? [])))->pluck('id')->all()) ?: throw new \InvalidArgumentException('Choose at least one person.'), 'message' => $msg()],
            'set_priority' => ['priority' => $prio(true)],
            'add_tag' => ['tag' => ($t = mb_substr(trim((string) ($a['tag'] ?? '')), 0, 50)) !== '' ? $t : throw new \InvalidArgumentException('Enter a tag.')],
            'move_stage' => ['status_id' => (LeadStatus::where('is_active', true)->where('category', 'open')->find((int) ($a['status_id'] ?? 0)) ?? throw new \InvalidArgumentException('Choose an open stage.'))->id],
            'cancel_tasks' => [],
            'call_webhook' => ['url' => $this->webhookUrl((string) ($a['url'] ?? '')), 'secret' => mb_substr(trim((string) ($a['secret'] ?? '')), 0, 128)],
            'send_whatsapp_template' => ['template_id' => (MessageTemplate::where('status', 'APPROVED')->find((int) ($a['template_id'] ?? 0)) ?? throw new \InvalidArgumentException('Choose an approved template.'))->id,
                'params' => mb_substr(trim((string) ($a['params'] ?? '')), 0, 500)],
        };
    }
}
