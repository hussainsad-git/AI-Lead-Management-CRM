<?php

namespace App\Services\Automation;

use App\Models\CallTask;
use App\Models\Lead;

/** Evaluates {"match": "all|any", "rules": [{field, op, value}]} against a lead (+ event context). */
class ConditionEvaluator
{
    public function passes(?array $conditions, Lead $lead, array $ctx = []): bool
    {
        $rules = $conditions['rules'] ?? [];
        if (! $rules) {
            return true;
        }
        $results = array_map(fn ($r) => $this->test($r, $lead, $ctx), $rules);

        return ($conditions['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /** Per-condition breakdown for the rule tester (no side effects). */
    public function explain(?array $conditions, Lead $lead, array $ctx = []): array
    {
        return array_map(fn ($r) => ['field' => $r['field'] ?? null, 'op' => $r['op'] ?? null, 'value' => $r['value'] ?? null,
            'actual' => $this->value((string) ($r['field'] ?? ''), $lead, $ctx), 'passed' => $this->test($r, $lead, $ctx)], $conditions['rules'] ?? []);
    }

    private function value(string $field, Lead $lead, array $ctx): mixed
    {
        $lead->loadMissing('status', 'source', 'contact', 'firstTouch');

        return match ($field) {
            'status' => $lead->status?->semantic->value,
            'source' => $lead->source?->key,
            'priority' => $lead->priority,
            'score_label' => $lead->score_label,
            'platform' => $lead->firstTouch?->platform,
            'campaign' => $lead->firstTouch?->campaign_id,
            'assigned' => $lead->assigned_user_id !== null,
            'has_pending_task' => CallTask::where('lead_id', $lead->id)->whereIn('status', ['pending', 'missed'])->exists(),
            'contact_attempts' => $lead->contact_attempts,
            'unanswered_attempts' => $lead->unanswered_attempts,
            'call_outcome' => $ctx['outcome_key'] ?? null,
            'product_interest' => $lead->product_interest,
            'city' => $lead->contact?->city ?: $lead->location,
            'budget_amount' => $lead->budget_amount !== null ? (float) $lead->budget_amount : null,
            'task_priority' => $ctx['task_priority'] ?? null,
            default => null,
        };
    }

    private function test(array $r, Lead $lead, array $ctx): bool
    {
        $v = $this->value((string) ($r['field'] ?? ''), $lead, $ctx);
        $want = $r['value'] ?? null;

        return match ($r['op'] ?? '') {
            'in' => in_array((string) $v, array_map('strval', (array) $want), true),
            'not_in' => ! in_array((string) $v, array_map('strval', (array) $want), true),
            'gte' => $v !== null && (float) $v >= (float) $want,
            'lte' => $v !== null && (float) $v <= (float) $want,
            'eq' => $v !== null && (float) $v == (float) $want,
            'contains' => $v !== null && $want !== null && str_contains(mb_strtolower((string) $v), mb_strtolower((string) $want)),
            'empty' => $v === null || $v === '',
            'not_empty' => $v !== null && $v !== '',
            'is' => (bool) $v === filter_var($want, FILTER_VALIDATE_BOOLEAN),
            default => false,
        };
    }
}
