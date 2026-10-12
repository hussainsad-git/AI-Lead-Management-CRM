<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\CallTask;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\User;

class AssignmentService
{
    public function __construct(private ActivityLogger $activity) {}

    public function assign(Lead $lead, ?int $userId, ?string $reason = null): Lead
    {
        $to = null;
        if ($userId) {
            $to = User::query()->inCurrentOrganization()->whereKey($userId)->first();
            if (! $to || ! $to->is_active) {
                throw new DomainRuleException('Selected user is not an active member of this organization.', 'invalid_assignee');
            }
        }
        $fromId = $lead->assigned_user_id;
        if ($fromId === $to?->id) {
            return $lead;
        }

        $lead->forceFill(['assigned_user_id' => $to?->id])->save();
        LeadAssignment::create([
            'lead_id' => $lead->id, 'from_user_id' => $fromId, 'to_user_id' => $to?->id,
            'assigned_by' => auth()->id(), 'reason' => $reason,
        ]);
        // Open work follows the lead.
        CallTask::where('lead_id', $lead->id)->where('status', 'pending')
            ->when($fromId, fn ($q) => $q->where('assigned_user_id', $fromId), fn ($q) => $q->whereNull('assigned_user_id'))
            ->update(['assigned_user_id' => $to?->id, 'updated_at' => now()]);

        $fromName = $fromId ? User::find($fromId)?->name : null;
        $this->activity->forLead($lead, $fromId ? 'reassigned' : 'assigned', $reason, ['from' => $fromName, 'to' => $to?->name]);

        return $lead->setRelation('assignee', $to);
    }
}
