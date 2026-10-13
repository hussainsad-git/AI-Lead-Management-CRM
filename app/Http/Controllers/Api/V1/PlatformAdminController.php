<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationProvisioner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Cross-business administration for platform operators (users.is_platform_admin).
 * Exposes counts only — never another business's lead or contact records.
 */
class PlatformAdminController extends ApiController
{
    private function guard(): void
    {
        abort_unless($this->user()->is_platform_admin, 403);
    }

    public function index()
    {
        $this->guard();
        $count = fn (string $table) => DB::table($table)->selectRaw('organization_id, COUNT(*) as c')->groupBy('organization_id')->pluck('c', 'organization_id');
        $users = $count('users');
        $leads = DB::table('leads')->whereNull('deleted_at')->selectRaw('organization_id, COUNT(*) as c')->groupBy('organization_id')->pluck('c', 'organization_id');
        $convs = $count('conversations');
        $integrations = DB::table('integrations')->where('status', 'connected')->get(['organization_id', 'provider'])->groupBy('organization_id');
        $msgs30 = DB::table('messages')->where('occurred_at', '>', now()->subDays(30))->selectRaw('organization_id, COUNT(*) as c')->groupBy('organization_id')->pluck('c', 'organization_id');
        $ai30 = DB::table('ai_interactions')->where('created_at', '>', now()->subDays(30))->selectRaw('organization_id, COUNT(*) as c, SUM(COALESCE(prompt_tokens,0)+COALESCE(completion_tokens,0)) as t')->groupBy('organization_id')->get()->keyBy('organization_id');

        return $this->ok([
            'organizations' => Organization::orderBy('name')->get()->map(fn ($o) => [
                'id' => $o->id, 'name' => $o->name, 'slug' => $o->slug, 'industry' => $o->industry, 'is_active' => $o->is_active,
                'timezone' => $o->timezone, 'currency' => $o->currency, 'created_at' => $o->created_at->toIso8601ZuluString(),
                'users' => (int) ($users[$o->id] ?? 0), 'leads' => (int) ($leads[$o->id] ?? 0), 'conversations' => (int) ($convs[$o->id] ?? 0),
                'messages_30d' => (int) ($msgs30[$o->id] ?? 0), 'ai_calls_30d' => (int) ($ai30[$o->id]->c ?? 0), 'ai_tokens_30d' => (int) ($ai30[$o->id]->t ?? 0),
                'connected' => ($integrations[$o->id] ?? collect())->pluck('provider')->values(),
            ]),
            'system' => [
                'php' => PHP_VERSION, 'laravel' => app()->version(), 'environment' => app()->environment(), 'debug' => (bool) config('app.debug'),
                'db' => config('database.default'), 'queue' => config('queue.default'),
                'jobs_pending' => DB::table('jobs')->count(), 'jobs_failed' => DB::table('failed_jobs')->count(),
                'oldest_pending_job_min' => ($t = DB::table('jobs')->min('created_at')) ? (int) round((time() - $t) / 60) : null,
                'webhooks_failed_24h' => DB::table('webhook_events')->where('status', 'failed')->where('created_at', '>', now()->subDay())->count(),
                'webhooks_24h' => DB::table('webhook_events')->where('created_at', '>', now()->subDay())->count(),
                'https' => str_starts_with((string) config('app.url'), 'https://'), 'session_secure' => (bool) config('session.secure'),
            ],
            'industries' => collect(config('ai.industries'))->map(fn ($v, $k) => ['key' => $k, 'label' => $v['label']])->values(),
        ]);
    }

    public function store(Request $request, OrganizationProvisioner $provisioner, CurrentOrganization $current, AuditLogger $audit)
    {
        $this->guard();
        $d = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'industry' => ['nullable', Rule::in(array_keys(config('ai.industries')))],
            'timezone' => ['required', 'timezone:all'], 'currency' => ['required', 'string', 'size:3', 'alpha'],
            'country' => ['required', Rule::in(config('leadflow.phone_regions'))],
            'admin_name' => ['required', 'string', 'max:120'], 'admin_email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'admin_password' => ['required', Password::min(10)->letters()->numbers()],
        ]);
        $org = DB::transaction(function () use ($d, $provisioner, $current) {
            $org = $provisioner->create($d['name'], $d['timezone'], strtoupper($d['currency']), $d['country']);
            $org->update(['industry' => $d['industry'] ?? 'general']);
            $current->runAs($org, function () use ($org, $d) {
                $assistants = app(\App\Services\Ai\AssistantConfig::class);
                $assistants->applyPreset($assistants->get(), $org->industry);
                $u = new User(['name' => $d['admin_name'], 'email' => $d['admin_email'], 'password' => $d['admin_password']]);
                $u->organization_id = $org->id;
                $u->save();
                $u->roles()->sync([Role::where('key', 'business_admin')->value('id')]);
            });

            return $org;
        });
        $audit->log('platform.organization_created', $org, [], ['name' => $org->name, 'admin' => $d['admin_email']]);

        return $this->ok(['id' => $org->id], [], 201);
    }

    public function update(Request $request, Organization $organization, AuditLogger $audit)
    {
        $this->guard();
        $d = $request->validate(['is_active' => ['required', 'boolean']]);
        abort_if(! $d['is_active'] && $organization->id === $this->user()->organization_id, 422, 'You cannot deactivate your own organization.');
        $organization->update($d);
        if (! $d['is_active']) {
            DB::table('sessions')->whereIn('user_id', User::where('organization_id', $organization->id)->pluck('id'))->delete();
        }
        $audit->log('platform.organization_'.($d['is_active'] ? 'activated' : 'deactivated'), $organization);

        return $this->ok(['saved' => true]);
    }
}
