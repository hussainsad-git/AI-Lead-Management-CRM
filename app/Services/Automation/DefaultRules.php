<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Services\OrganizationSettings;

/**
 * Starter rules every business gets (idempotent by template_key; never overwrites edits).
 * Callback scheduling and opt-out suppression are built into the domain services, so they are
 * not rules: a disabled rule must never be able to make the CRM call someone who opted out.
 */
class DefaultRules
{
    public function seed(): void
    {
        $sla = (int) (app(OrganizationSettings::class)->get('first_contact_sla_minutes') ?? 30);
        $rules = [
            'first_contact' => [
                'name' => __('Assign new leads and create a first-contact task'),
                'description' => __('Round-robin assignment for unassigned leads, then a call task due immediately.'),
                'trigger' => 'lead.created', 'delay_minutes' => 0, 'is_active' => false,
                'conditions' => ['match' => 'all', 'rules' => []],
                'actions' => [['type' => 'assign_round_robin'], ['type' => 'create_task', 'purpose' => 'First contact: {name}', 'in_minutes' => 0]],
            ],
            'sla_uncontacted' => [
                'name' => __('First-contact SLA missed'),
                'description' => __("Remind the owner, and tell their manager, when nobody has contacted a new lead within :minutes minutes.", ["minutes" => $sla]),
                'trigger' => 'lead.uncontacted', 'delay_minutes' => $sla, 'is_active' => true,
                'conditions' => ['match' => 'all', 'rules' => []],
                'actions' => [['type' => 'notify_owner', 'message' => 'SLA missed: {name} has not been contacted yet'], ['type' => 'notify_manager', 'message' => 'SLA missed: {name} ({owner}) not contacted']],
            ],
            'overdue_escalation' => [
                'name' => __('Escalate overdue high-priority follow-ups'),
                'description' => __('Tell the manager when a high or urgent follow-up is more than an hour overdue.'),
                'trigger' => 'followup.overdue', 'delay_minutes' => 60, 'is_active' => true,
                'conditions' => ['match' => 'all', 'rules' => [['field' => 'task_priority', 'op' => 'in', 'value' => ['high', 'urgent']]]],
                'actions' => [['type' => 'notify_manager', 'message' => 'Overdue follow-up: {name} ({owner})']],
            ],
            'qualified' => [
                'name' => __('Lead qualified → alert the team'),
                'description' => __('Notify the owner and manager so the next sales step happens quickly.'),
                'trigger' => 'lead.qualified', 'delay_minutes' => 0, 'is_active' => true,
                'conditions' => ['match' => 'all', 'rules' => []],
                'actions' => [['type' => 'notify_owner', 'message' => 'Qualified: {name} — schedule the next step'], ['type' => 'notify_manager', 'message' => 'Qualified lead: {name} ({owner})']],
            ],
            'unanswered' => [
                'name' => __('Three unanswered calls → suggest another approach'),
                'description' => __('After 3 unanswered calls in a row with no follow-up booked, prompt the owner to try WhatsApp or another time.'),
                'trigger' => 'call.logged', 'delay_minutes' => 0, 'is_active' => true,
                'conditions' => ['match' => 'all', 'rules' => [['field' => 'unanswered_attempts', 'op' => 'gte', 'value' => 3], ['field' => 'has_pending_task', 'op' => 'is', 'value' => false]]],
                'actions' => [['type' => 'notify_owner', 'message' => '{name}: 3 unanswered calls — try WhatsApp or a different time of day']],
            ],
        ];
        foreach ($rules as $key => $r) {
            AutomationRule::firstOrCreate(['template_key' => $key], $r);
        }
    }
}
