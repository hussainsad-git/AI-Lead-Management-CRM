<?php

namespace App\Services;

use App\Models\LeadAssignment;
use App\Models\User;

/**
 * Round-robin for automatically captured leads: the eligible user who least recently received an
 * assignment gets the next one. Eligible = active, accepts assignments, in the configured pool.
 */
class AssignmentRouter
{
    public function __construct(private OrganizationSettings $settings) {}

    public function next(): ?User
    {
        $cfg = (array) ($this->settings->get('auto_assign') ?? []);
        if (empty($cfg['enabled'])) {
            return null;
        }
        $q = User::query()->inCurrentOrganization()->where('is_active', true)->where('accepts_assignments', true);
        if (! empty($cfg['user_ids'])) {
            $q->whereIn('id', array_map('intval', $cfg['user_ids']));
        } elseif (! empty($cfg['team_id'])) {
            $q->where('team_id', (int) $cfg['team_id']);
        } else {
            $q->whereHas('roles', fn ($r) => $r->where('key', 'sales_rep'));
        }
        $ids = $q->pluck('id');
        if ($ids->isEmpty()) {
            return null;
        }
        $last = LeadAssignment::whereIn('to_user_id', $ids)->selectRaw('to_user_id, MAX(id) as last_id')->groupBy('to_user_id')->pluck('last_id', 'to_user_id');
        $pick = $ids->sortBy(fn ($id) => $last[$id] ?? 0)->first();

        return User::find($pick);
    }
}
