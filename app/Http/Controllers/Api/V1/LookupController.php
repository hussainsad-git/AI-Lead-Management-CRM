<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Priority;
use App\Models\CallOutcome;
use App\Models\Campaign;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Services\LeadExportService;
use App\Support\Tenancy\CurrentOrganization;

/** Reference data for forms and filters, in one round trip. */
class LookupController extends ApiController
{
    public function __invoke(CurrentOrganization $current)
    {
        $org = $current->require();
        $user = $this->user();

        return $this->ok([
            'organization' => ['name' => $org->name, 'timezone' => $org->timezone, 'currency' => $org->currency, 'default_country' => $org->default_country],
            'me' => ['id' => $user->id, 'name' => $user->name, 'permissions' => $user->permissionKeys()],
            'statuses' => LeadStatus::orderBy('sort_order')->get(['id', 'key', 'name', 'semantic', 'category', 'color', 'requires_reason', 'is_active', 'is_default']),
            'sources' => LeadSource::orderBy('sort_order')->get(['id', 'key', 'name', 'platform', 'is_active']),
            'outcomes' => CallOutcome::where('is_active', true)->orderBy('sort_order')->get(['id', 'key', 'name', 'is_connected', 'creates_callback']),
            'users' => User::query()->inCurrentOrganization()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'team_id', 'accepts_assignments']),
            'teams' => Team::orderBy('name')->get(['id', 'name']),
            'tags' => Tag::orderBy('name')->get(['id', 'name']),
            'campaigns' => $user->hasAnyPermission('campaigns.view', 'leads.create')
                ? Campaign::orderBy('name')->get(['id', 'name', 'platform', 'status']) : [],
            'priorities' => Priority::values(),
            'columns' => LeadExportService::COLUMNS,
            'phone_regions' => config('leadflow.phone_regions'),
        ]);
    }
}
