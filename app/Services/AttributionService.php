<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadAttribution;

/**
 * Records first/last-touch attribution. External ids and display-name snapshots are stored
 * alongside FKs so that later campaign renames never rewrite historical attribution.
 */
class AttributionService
{
    /**
     * @param  array{platform?:?string, source?:?string, medium?:?string, campaign_id?:?int, campaign_name?:?string,
     *               external_campaign_id?:?string, ad_set_name?:?string, external_ad_set_id?:?string,
     *               ad_name?:?string, external_ad_id?:?string, referral?:?array}  $data
     */
    public function record(Lead $lead, array $data, string $capturedVia, bool $createMissing = false): ?LeadAttribution
    {
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');
        if (! $data) {
            return null;
        }
        [$campaign, $adSet, $ad] = $this->resolveEntities($data, $createMissing);

        $completeness = match (true) {
            $campaign && $adSet && $ad => 'complete',
            (bool) $campaign, ! empty($data['external_ad_id']) => 'partial',
            default => 'unknown',
        };

        $payload = [
            'platform' => $data['platform'] ?? $campaign?->platform,
            'source' => $data['source'] ?? null,
            'medium' => $data['medium'] ?? null,
            'campaign_id' => $campaign?->id,
            'ad_set_id' => $adSet?->id,
            'ad_id' => $ad?->id,
            'external_campaign_id' => $data['external_campaign_id'] ?? $campaign?->external_id,
            'external_ad_set_id' => $data['external_ad_set_id'] ?? $adSet?->external_id,
            'external_ad_id' => $data['external_ad_id'] ?? $ad?->external_id,
            'campaign_name_snapshot' => $campaign?->name ?? ($data['campaign_name'] ?? null),
            'ad_set_name_snapshot' => $adSet?->name ?? ($data['ad_set_name'] ?? null),
            'ad_name_snapshot' => $ad?->name ?? ($data['ad_name'] ?? null),
            'referral' => $data['referral'] ?? null,
            'completeness' => $completeness,
            'captured_via' => $capturedVia,
            'captured_at' => now(),
        ];

        $first = LeadAttribution::where('lead_id', $lead->id)->where('touch', 'first')->first();
        if (! $first) {
            LeadAttribution::create($payload + ['lead_id' => $lead->id, 'touch' => 'first']);
        }

        return LeadAttribution::updateOrCreate(['lead_id' => $lead->id, 'touch' => 'last'], $payload);
    }

    private function resolveEntities(array $d, bool $createMissing): array
    {
        $campaign = null;
        if (! empty($d['campaign_id'])) {
            $campaign = Campaign::find($d['campaign_id']);
        }
        if (! $campaign && ! empty($d['external_campaign_id'])) {
            $campaign = Campaign::where('external_id', $d['external_campaign_id'])->first();
        }
        if (! $campaign && ! empty($d['campaign_name'])) {
            $campaign = Campaign::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($d['campaign_name']))])->first();
            if (! $campaign && $createMissing) {
                $campaign = Campaign::create([
                    'platform' => $d['platform'] ?? 'other', 'name' => trim($d['campaign_name']),
                    'external_id' => $d['external_campaign_id'] ?? null,
                    'currency' => app(\App\Support\Tenancy\CurrentOrganization::class)->require()->currency,
                    'data_source' => 'import',
                ]);
            }
        }
        if (! $campaign && ! empty($d['external_ad_id'])) {
            // Click-to-WhatsApp referrals carry only the ad id; link it if campaigns were synced/entered with ids.
            $ad = Ad::where('external_id', $d['external_ad_id'])->first();
            if ($ad) {
                return [$ad->campaign, $ad->adSet, $ad];
            }
        }
        if (! $campaign) {
            return [null, null, null];
        }

        $adSet = $this->child(AdSet::class, $campaign, $d['external_ad_set_id'] ?? null, $d['ad_set_name'] ?? null, $createMissing);
        $ad = $this->child(Ad::class, $campaign, $d['external_ad_id'] ?? null, $d['ad_name'] ?? null, $createMissing, $adSet?->id);

        return [$campaign, $adSet, $ad];
    }

    private function child(string $class, Campaign $campaign, ?string $externalId, ?string $name, bool $create, ?int $adSetId = null)
    {
        $q = $class::where('campaign_id', $campaign->id);
        $model = null;
        if ($externalId) {
            $model = (clone $q)->where('external_id', $externalId)->first();
        }
        if (! $model && $name) {
            $model = (clone $q)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->first();
        }
        if (! $model && $create && $name) {
            $attrs = ['campaign_id' => $campaign->id, 'name' => trim($name), 'external_id' => $externalId];
            if ($class === Ad::class) {
                $attrs['ad_set_id'] = $adSetId;
            }
            $model = $class::create($attrs);
        }

        return $model;
    }
}
