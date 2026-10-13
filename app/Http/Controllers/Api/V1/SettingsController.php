<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusSemantic;
use App\Models\CallOutcome;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Services\AuditLogger;
use App\Services\OrganizationSettings;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SettingsController extends ApiController
{
    public function __construct(private AuditLogger $audit)
    {
    }

    private function guard(): void
    {
        abort_unless($this->user()->hasPermission('settings.manage'), 403);
    }

    public function show(CurrentOrganization $org, OrganizationSettings $settings)
    {
        $this->guard();

        return $this->ok([
            'organization' => $org->require()->only(['name', 'timezone', 'currency', 'default_country', 'industry', 'brand_color', 'default_locale']) + ['has_logo' => (bool) $org->require()->logo_path],
            'settings' => $settings->all(),
            'business_hours' => app(\App\Services\BusinessHours::class)->schedule(),
            'auto_assign' => (array) ($settings->get('auto_assign') ?? ['enabled' => false]),
            'scoring_weights' => app(\App\Services\LeadScoring::class)->weights(),
            'scoring_defaults' => \App\Services\LeadScoring::DEFAULT_WEIGHTS,
            'industries' => collect(config('ai.industries'))->map(fn ($v, $k) => ['key' => $k, 'label' => $v['label']])->values(),
            'locales' => config('locales'),
            'users' => \App\Models\User::query()->inCurrentOrganization()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'team_id']),
            'teams' => \App\Models\Team::orderBy('name')->get(['id', 'name']),
            'statuses' => LeadStatus::orderBy('sort_order')->withCount('leads')->get(),
            'sources' => LeadSource::orderBy('sort_order')->get(),
            'outcomes' => CallOutcome::orderBy('sort_order')->get(),
            'semantics' => StatusSemantic::values(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function updateOrganization(Request $request, CurrentOrganization $current, OrganizationSettings $settings)
    {
        $this->guard();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'default_country' => ['required', 'in:'.implode(',', config('leadflow.phone_regions'))],
            'settings.required_identifier' => ['required', 'in:phone_or_whatsapp,any'],
            'settings.allow_duplicate_open_leads' => ['required', 'boolean'],
            'settings.first_contact_sla_minutes' => ['required', 'integer', 'min:5', 'max:10080'],
        ]);
        $org = $current->require();
        $before = $org->only(['name', 'timezone', 'currency', 'default_country']) + $settings->all();
        $org->update(['name' => $data['name'], 'timezone' => $data['timezone'], 'currency' => strtoupper($data['currency']), 'default_country' => $data['default_country']]);
        foreach ($data['settings'] as $k => $v) {
            $settings->set($k, $k === 'allow_duplicate_open_leads' ? (bool) $v : $v);
        }
        $this->audit->log('settings.organization_updated', $org, ['before' => $before, 'after' => $org->only(['name', 'timezone', 'currency', 'default_country']) + $settings->all()]);

        return $this->ok(['saved' => true]);
    }

    public function updateBusiness(Request $request, CurrentOrganization $current, OrganizationSettings $settings)
    {
        $this->guard();
        $hhmm = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';
        $data = $request->validate([
            'industry' => ['required', Rule::in(array_keys(config('ai.industries')))],
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'default_locale' => ['required', Rule::in(array_keys(config('locales')))],
            'business_hours' => ['required', 'array'],
            'business_hours.*' => ['nullable', 'array', 'size:2'], 'business_hours.*.*' => ['string', $hhmm],
            'auto_assign.enabled' => ['required', 'boolean'],
            'auto_assign.team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')->where('organization_id', $current->id())],
            'auto_assign.user_ids' => ['nullable', 'array'], 'auto_assign.user_ids.*' => ['integer', Rule::exists('users', 'id')->where('organization_id', $current->id())],
            'scoring_weights' => ['nullable', 'array'], 'scoring_weights.*' => ['integer', 'min:-50', 'max:50'],
        ]);
        foreach ($data['business_hours'] as $day => $span) {
            abort_unless(in_array($day, \App\Services\BusinessHours::DAYS, true), 422, 'Unknown day.');
            abort_if($span && $span[0] >= $span[1], 422, 'Opening time must be before closing time ('.$day.').');
        }
        $current->require()->update(['industry' => $data['industry'], 'brand_color' => $data['brand_color'] ?? null, 'default_locale' => $data['default_locale']]);
        $settings->set('business_hours', $data['business_hours']);
        $settings->set('auto_assign', $data['auto_assign']);
        if (isset($data['scoring_weights'])) {
            $settings->set('scoring_weights', array_intersect_key($data['scoring_weights'], \App\Services\LeadScoring::DEFAULT_WEIGHTS));
        }
        $this->audit->log('settings.business_updated', $current->require(), [], ['industry' => $data['industry'], 'auto_assign' => $data['auto_assign']['enabled']]);

        return $this->ok(['saved' => true]);
    }

    public function uploadLogo(Request $request, CurrentOrganization $current)
    {
        $this->guard();
        $request->validate(['logo' => ['required', 'file', 'max:1024', 'mimes:png,jpg,jpeg,webp']]);
        $org = $current->require();
        if ($org->logo_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($org->logo_path);
        }
        $org->update(['logo_path' => $request->file('logo')->store('branding/'.$org->id, 'local')]);

        return $this->ok(['saved' => true]);
    }

    public function storeStatus(Request $request)
    {
        $this->guard();
        $data = $this->statusRules($request, null);
        $sem = StatusSemantic::from($data['semantic']);
        $status = LeadStatus::create($data + [
            'key' => $this->uniqueKey(LeadStatus::class, $data['name']), 'category' => $sem->category(),
            'sort_order' => (int) LeadStatus::max('sort_order') + 1,
            'requires_reason' => $data['requires_reason'] ?? $this->defaultReason($sem),
        ]);
        $this->audit->log('settings.status_created', $status, [], ['name' => $status->name]);

        return $this->ok($status, [], 201);
    }

    public function updateStatus(Request $request, LeadStatus $status)
    {
        $this->guard();
        $data = $this->statusRules($request, $status);
        if (isset($data['semantic'])) {
            $sem = StatusSemantic::from($data['semantic']);
            $data['category'] = $sem->category();
            if ($sem === StatusSemantic::DoNotContact) {
                $data['requires_reason'] = 'opt_out_reason';
            }
        }
        if (($data['is_active'] ?? true) === false) {
            abort_if($status->is_default, 422, 'Choose another default stage before disabling this one.');
            abort_if(Lead::where('lead_status_id', $status->id)->whereNull('archived_at')->exists(), 422, 'Move leads out of this stage before disabling it.');
        }
        DB::transaction(function () use ($status, $data) {
            if (! empty($data['is_default'])) {
                abort_if(($data['category'] ?? $status->category) !== 'open', 422, 'The default stage must be an open stage.');
                LeadStatus::where('id', '!=', $status->id)->update(['is_default' => false]);
            }
            $status->update($data);
        });
        $this->audit->log('settings.status_updated', $status, $data);

        return $this->ok($status->fresh());
    }

    public function reorderStatuses(Request $request)
    {
        $this->guard();
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        DB::transaction(function () use ($data) {
            foreach (array_values($data['ids']) as $i => $id) {
                LeadStatus::whereKey($id)->update(['sort_order' => $i]);
            }
        });

        return $this->ok(['saved' => true]);
    }

    public function storeSource(Request $request)
    {
        $this->guard();
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'platform' => ['nullable', 'in:facebook,instagram,meta,whatsapp,web,offline,import,other'], 'is_paid' => ['boolean']]);
        abort_if(LeadSource::whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->exists(), 422, 'A source with that name exists.');
        $src = LeadSource::create($data + ['key' => $this->uniqueKey(LeadSource::class, $data['name']), 'sort_order' => (int) LeadSource::max('sort_order') + 1]);

        return $this->ok($src, [], 201);
    }

    public function updateSource(Request $request, LeadSource $source)
    {
        $this->guard();
        $source->update($request->validate(['name' => ['sometimes', 'string', 'max:120'], 'platform' => ['nullable', 'in:facebook,instagram,meta,whatsapp,web,offline,import,other'], 'is_paid' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean']]));

        return $this->ok($source);
    }

    public function storeOutcome(Request $request)
    {
        $this->guard();
        $data = $this->outcomeRules($request, false);
        $o = CallOutcome::create($data + ['key' => $this->uniqueKey(CallOutcome::class, $data['name']), 'sort_order' => (int) CallOutcome::max('sort_order') + 1]);
        $this->audit->log('settings.outcome_created', $o, [], ['name' => $o->name]);

        return $this->ok($o, [], 201);
    }

    public function updateOutcome(Request $request, CallOutcome $outcome)
    {
        $this->guard();
        $outcome->update($this->outcomeRules($request, true, $outcome));

        return $this->ok($outcome);
    }

    private function statusRules(Request $request, ?LeadStatus $s): array
    {
        return $request->validate([
            'name' => [$s ? 'sometimes' : 'required', 'string', 'max:120'],
            'semantic' => [$s ? 'sometimes' : 'required', Rule::in(StatusSemantic::values())],
            'color' => ['sometimes', 'in:primary,secondary,success,danger,warning,info,dark,teal,indigo,purple'],
            'requires_reason' => ['sometimes', 'in:none,lost_reason,opt_out_reason'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    private function outcomeRules(Request $request, bool $partial, ?CallOutcome $existing = null): array
    {
        $data = $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'is_connected' => ['sometimes', 'boolean'],
            'creates_callback' => ['sometimes', 'boolean'],
            // An unanswered outcome may never close a lead as not interested.
            'advances_to_semantic' => ['nullable', Rule::in(StatusSemantic::values())],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $connected = $data['is_connected'] ?? $existing?->is_connected ?? false;
        $target = array_key_exists('advances_to_semantic', $data) ? $data['advances_to_semantic'] : $existing?->advances_to_semantic;
        if (! $connected && in_array($target, ['not_interested', 'interested', 'qualified', 'won', 'responded'], true)) {
            abort(422, 'An outcome where nobody answered can only advance to Contact Attempted, No Response or Lost (e.g. wrong number).');
        }

        return $data;
    }

    private function defaultReason(StatusSemantic $sem): string
    {
        return match ($sem) {
            StatusSemantic::DoNotContact => 'opt_out_reason',
            StatusSemantic::Lost, StatusSemantic::NotInterested => 'lost_reason',
            default => 'none',
        };
    }

    private function uniqueKey(string $class, string $name): string
    {
        $base = Str::slug($name, '_') ?: 'item';
        $key = $base;
        for ($i = 2; $class::where('key', $key)->exists(); $i++) {
            $key = $base.'_'.$i;
        }

        return $key;
    }
}
