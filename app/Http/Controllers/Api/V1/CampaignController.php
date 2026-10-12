<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Campaign;
use App\Models\CampaignSpend;
use App\Models\LeadAttribution;
use App\Services\AuditLogger;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Campaigns + manually recorded spend. Meta API sync (Phase 4) writes with data_source=meta_api. */
class CampaignController extends ApiController
{
    public function index(Request $request)
    {
        abort_unless($this->user()->hasPermission('campaigns.view'), 403);
        $campaigns = Campaign::query()
            ->when($request->input('q'), fn ($q, $s) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($s).'%']))
            ->orderByDesc('id')->get();
        $ids = $campaigns->pluck('id');
        $leads = LeadAttribution::where('touch', 'first')->whereIn('campaign_id', $ids)
            ->join('leads', 'leads.id', '=', 'lead_attributions.lead_id')->whereNull('leads.deleted_at')
            ->select('campaign_id', DB::raw('COUNT(*) as leads'),
                DB::raw('SUM(CASE WHEN leads.qualified_at IS NOT NULL THEN 1 ELSE 0 END) as qualified'),
                DB::raw('SUM(CASE WHEN leads.converted_at IS NOT NULL THEN 1 ELSE 0 END) as converted'))
            ->groupBy('campaign_id')->get()->keyBy('campaign_id');
        $spend = CampaignSpend::whereIn('campaign_id', $ids)->select('campaign_id', 'currency', DB::raw('SUM(amount) as total'),
            DB::raw('MIN(spend_date) as first_day'), DB::raw('MAX(spend_date) as last_day'))
            ->groupBy('campaign_id', 'currency')->get()->groupBy('campaign_id');

        return $this->ok($campaigns->map(function ($c) use ($leads, $spend) {
            $l = $leads[$c->id] ?? null;
            $s = ($spend[$c->id] ?? collect())->firstWhere('currency', $c->currency);
            $total = $s ? round((float) $s->total, 2) : null;
            $n = (int) ($l->leads ?? 0);

            return [
                'id' => $c->id, 'name' => $c->name, 'platform' => $c->platform, 'external_id' => $c->external_id,
                'objective' => $c->objective, 'status' => $c->status, 'currency' => $c->currency,
                'starts_on' => $c->starts_on ? substr($c->starts_on, 0, 10) : null, 'ends_on' => $c->ends_on ? substr($c->ends_on, 0, 10) : null,
                'data_source' => $c->data_source, 'last_synced_at' => $c->last_synced_at?->toIso8601ZuluString(),
                'leads' => $n, 'qualified' => (int) ($l->qualified ?? 0), 'converted' => (int) ($l->converted ?? 0),
                'spend' => $total, 'spend_from' => $s?->first_day, 'spend_to' => $s?->last_day,
                'cpl' => $total !== null && $n ? round($total / $n, 2) : null,
                'spend_note' => $total === null ? 'No spend recorded' : 'Lifetime recorded spend; CPL uses all-time attributed leads',
            ];
        }));
    }

    public function store(Request $request, CurrentOrganization $org, AuditLogger $audit)
    {
        abort_unless($this->user()->hasPermission('campaigns.manage'), 403);
        $data = $this->validated($request, null);
        $campaign = Campaign::create($data + ['currency' => $data['currency'] ?? $org->require()->currency, 'data_source' => 'manual']);
        $audit->log('campaign.created', $campaign, [], ['name' => $campaign->name]);

        return $this->ok($campaign, [], 201);
    }

    public function update(Request $request, Campaign $campaign)
    {
        abort_unless($this->user()->hasPermission('campaigns.manage'), 403);
        // Renaming never rewrites history: lead_attributions keep their name snapshots.
        $campaign->update($this->validated($request, $campaign));

        return $this->ok($campaign);
    }

    public function spend(Campaign $campaign)
    {
        abort_unless($this->user()->hasPermission('campaigns.view'), 403);

        return $this->ok($campaign->spend()->orderByDesc('spend_date')->limit(400)->get(['id', 'spend_date', 'amount', 'currency', 'data_source']));
    }

    public function recordSpend(Request $request, Campaign $campaign, AuditLogger $audit)
    {
        abort_unless($this->user()->hasPermission('campaigns.manage'), 403);
        $data = $request->validate([
            'spend_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ]);
        abort_if($campaign->data_source === 'meta_api', 409, 'Spend for API-synced campaigns comes from Meta.');
        $row = CampaignSpend::updateOrCreate(
            ['campaign_id' => $campaign->id, 'spend_date' => $data['spend_date'], 'data_source' => 'manual'],
            ['amount' => $data['amount'], 'currency' => $campaign->currency, 'recorded_by' => $this->user()->id]
        );
        $audit->log('campaign.spend_recorded', $campaign, [], ['date' => $data['spend_date'], 'amount' => $data['amount'], 'currency' => $campaign->currency]);

        return $this->ok($row, [], 201);
    }

    public function deleteSpend(Campaign $campaign, CampaignSpend $spend, AuditLogger $audit)
    {
        abort_unless($this->user()->hasPermission('campaigns.manage'), 403);
        abort_unless($spend->campaign_id === $campaign->id && $spend->data_source === 'manual', 404);
        $audit->log('campaign.spend_deleted', $campaign, [], ['date' => substr($spend->spend_date, 0, 10), 'amount' => $spend->amount]);
        $spend->delete();

        return $this->ok(['deleted' => true]);
    }

    private function validated(Request $request, ?Campaign $c): array
    {
        $org = app(CurrentOrganization::class)->id();

        return $request->validate([
            'name' => [$c ? 'sometimes' : 'required', 'string', 'max:191'],
            'platform' => [$c ? 'sometimes' : 'required', 'in:facebook,instagram,meta,google,whatsapp,other'],
            'external_id' => ['nullable', 'string', 'max:64', Rule::unique('campaigns')->where('organization_id', $org)->where('platform', $request->input('platform', $c?->platform))->ignore($c?->id)],
            'objective' => ['nullable', 'string', 'max:60'],
            'status' => ['sometimes', 'in:active,paused,ended'],
            'currency' => [$c ? 'prohibited' : 'nullable', 'string', 'size:3', 'alpha'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);
    }
}
