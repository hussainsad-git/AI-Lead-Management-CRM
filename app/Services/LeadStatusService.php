<?php

namespace App\Services;

use App\Enums\StatusSemantic;
use App\Exceptions\DomainRuleException;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * All status changes go through here so lifecycle timestamps, history, timeline,
 * reason requirements and opt-out side effects are applied consistently.
 */
class LeadStatusService
{
    public function __construct(private ActivityLogger $activity, private ConsentService $consent, private AuditLogger $audit) {}

    public function transition(Lead $lead, LeadStatus $to, ?string $reason = null, string $via = 'user'): Lead
    {
        if (! $to->is_active) {
            throw new DomainRuleException('That pipeline stage is disabled.', 'status_inactive');
        }
        $reason = $reason !== null ? trim($reason) : null;
        if ($to->requires_reason !== 'none' && ! $reason) {
            throw new DomainRuleException(
                $to->requires_reason === 'opt_out_reason' ? 'An opt-out reason is required for Do Not Contact.' : 'A reason is required when closing a lead as lost.',
                'reason_required', 422, ['requires_reason' => $to->requires_reason]
            );
        }

        $from = $lead->status;
        if ($from && $from->id === $to->id) {
            return $lead;
        }

        return DB::transaction(function () use ($lead, $from, $to, $reason, $via) {
            $now = now();
            $sem = $to->semantic;
            $fill = ['lead_status_id' => $to->id];

            if ($to->category === 'open') {
                $fill['closed_at'] = null;
                $fill['lost_reason'] = null;
                if ($from?->semantic === StatusSemantic::Won) {
                    $fill['converted_at'] = null; // reopened deals must not count as conversions
                }
                if ($sem->rank() >= StatusSemantic::Responded->rank() && $sem !== StatusSemantic::Custom) {
                    $fill['first_responded_at'] = $lead->first_responded_at ?? $now;
                }
                if ($sem->rank() >= StatusSemantic::Qualified->rank() && $sem !== StatusSemantic::Custom) {
                    $fill['qualified_at'] = $lead->qualified_at ?? $now;
                }
            } else {
                $fill['closed_at'] = $now;
                if ($to->category === 'won') {
                    $fill['converted_at'] = $lead->converted_at ?? $now;
                    $fill['lost_reason'] = null;
                    $fill['first_responded_at'] = $lead->first_responded_at ?? $now;
                    $fill['qualified_at'] = $lead->qualified_at ?? $now; // a converted lead was, by definition, qualified
                } else {
                    $fill['lost_reason'] = $reason;
                    $fill['converted_at'] = null;
                }
            }
            $lead->forceFill($fill)->save();
            $lead->setRelation('status', $to);

            LeadStatusHistory::create([
                'lead_id' => $lead->id, 'from_status_id' => $from?->id, 'to_status_id' => $to->id,
                'reason' => $reason, 'changed_by' => auth()->id(), 'changed_via' => $via,
            ]);
            $this->activity->forLead($lead, 'status_changed', $reason, [
                'from' => $from?->name, 'to' => $to->name, 'via' => $via,
            ]);

            if ($sem === StatusSemantic::DoNotContact) {
                $this->consent->optOut($lead->contact, 'all', $reason, 'status_change');
            }
            if (in_array($to->category, ['won', 'lost'], true)) {
                $lead->callTasks()->where('status', 'pending')->update(['status' => 'cancelled', 'cancel_reason' => 'Lead closed', 'updated_at' => now()]);
                $lead->forceFill(['next_follow_up_at' => null])->saveQuietly();
            }

            return $lead;
        });
    }

    /**
     * Automatic, forward-only advancement (e.g. from a call outcome). Never moves a closed lead,
     * never moves backwards, and an unanswered call can only ever mark "Contact Attempted".
     */
    public function advance(Lead $lead, StatusSemantic $target, ?string $reason, string $via): ?Lead
    {
        $current = $lead->status;
        if (! $current || $current->category !== 'open') {
            return null;
        }
        $isClosing = $target->category() !== 'open';
        if (! $isClosing && $target->rank() <= $current->semantic->rank()) {
            return null;
        }
        $status = LeadStatus::where('semantic', $target->value)->where('is_active', true)->orderBy('sort_order')->first();
        if (! $status) {
            return null; // organization disabled that stage; do nothing rather than guess
        }

        return $this->transition($lead, $status, $reason, $via);
    }

    public function defaultStatus(): LeadStatus
    {
        return LeadStatus::where('is_active', true)->where('is_default', true)->first()
            ?? LeadStatus::where('is_active', true)->where('category', 'open')->orderBy('sort_order')->firstOrFail();
    }
}
