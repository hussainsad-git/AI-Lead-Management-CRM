<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for record-level lead visibility. Every list, detail view,
 * pipeline, follow-up queue, dashboard metric and export goes through apply().
 */
class LeadVisibility
{
    /** @return array<int>|null null = unrestricted within the organization; [] = nothing */
    public function assigneeScope(User $user): ?array
    {
        if ($user->hasPermission('leads.view_all')) {
            return null;
        }
        if ($user->hasPermission('leads.view_team')) {
            return $user->teamMemberIds();
        }
        if ($user->hasPermission('leads.view_own')) {
            return [$user->id];
        }

        return [];
    }

    public function apply(Builder $query, User $user, string $column = 'leads.assigned_user_id'): Builder
    {
        $ids = $this->assigneeScope($user);
        if ($ids === null) {
            return $query;
        }

        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn($column, $ids);
    }

    public function canSee(User $user, Lead $lead): bool
    {
        $ids = $this->assigneeScope($user);

        return $ids === null || in_array((int) $lead->assigned_user_id, $ids, true);
    }

    public function canSeeAny(User $user): bool
    {
        return $user->hasAnyPermission('leads.view_all', 'leads.view_team', 'leads.view_own');
    }
}
