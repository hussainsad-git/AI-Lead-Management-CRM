<?php

namespace App\Services;

use App\Models\CallOutcome;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Creates an organization with default roles, pipeline, sources and call outcomes. Idempotent. */
class OrganizationProvisioner
{
    public function __construct(private PermissionCatalog $catalog, private CurrentOrganization $current) {}

    public function create(string $name, string $timezone = 'Asia/Karachi', string $currency = 'PKR', string $country = 'PK'): Organization
    {
        return DB::transaction(function () use ($name, $timezone, $currency, $country) {
            $base = Str::slug($name) ?: 'org';
            $slug = $base;
            for ($i = 2; Organization::where('slug', $slug)->exists(); $i++) {
                $slug = $base.'-'.$i;
            }
            $org = Organization::create(compact('name', 'slug', 'timezone', 'currency') + ['default_country' => $country]);
            $this->provision($org);

            return $org;
        });
    }

    public function provision(Organization $org): void
    {
        $this->catalog->sync();
        $this->current->runAs($org, function () {
            $allIds = Permission::pluck('id', 'key');
            foreach (config('leadflow.roles') as $key => [$name, $description, $perms]) {
                $role = Role::firstOrCreate(['key' => $key], ['name' => $name, 'description' => $description, 'is_system' => true]);
                if ($role->wasRecentlyCreated) {
                    $ids = $perms === ['*'] ? $allIds->values() : $allIds->only($perms)->values();
                    $role->permissions()->sync($ids);
                }
            }

            $i = 0;
            foreach (config('leadflow.lead_statuses') as $key => [$name, $semantic, $color, $reason]) {
                LeadStatus::firstOrCreate(['key' => $key], [
                    'name' => $name, 'semantic' => $semantic, 'category' => \App\Enums\StatusSemantic::from($semantic)->category(),
                    'color' => $color, 'requires_reason' => $reason, 'sort_order' => $i++, 'is_default' => $key === 'new',
                ]);
            }

            $i = 0;
            foreach (config('leadflow.lead_sources') as $key => [$name, $platform, $paid]) {
                LeadSource::firstOrCreate(['key' => $key], ['name' => $name, 'platform' => $platform, 'is_paid' => $paid, 'sort_order' => $i++]);
            }

            app(\App\Services\Ai\AssistantConfig::class)->get();

            $i = 0;
            foreach (config('leadflow.call_outcomes') as $key => [$name, $connected, $callback, $advance]) {
                CallOutcome::firstOrCreate(['key' => $key], [
                    'name' => $name, 'is_connected' => $connected, 'creates_callback' => $callback,
                    'advances_to_semantic' => $advance, 'sort_order' => $i++,
                ]);
            }

            app(\App\Services\Automation\DefaultRules::class)->seed();
            app(\App\Services\Audiences\DefaultSegments::class)->seed();
        });
    }
}
