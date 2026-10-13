<?php

namespace App\Services\Meta;

use App\Models\Ad;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignSpend;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pulls campaign structure, daily spend and Meta-reported aggregate actions. Writes:
 *  - campaigns / ad_sets / ads keyed by Meta ids (names refreshed; attribution snapshots untouched)
 *  - campaign_spend rows with data_source = meta_api, in the ad account's currency
 *  - campaign_events aggregates (no person data): messaging conversations started, link clicks, form leads
 */
class MetaSyncService
{
    /** Meta action_type → campaign_events.event_type */
    public const ACTIONS = [
        'onsite_conversion.messaging_conversation_started_7d' => 'conversation_started',
        'link_click' => 'link_click',
        'lead' => 'lead_form',
    ];

    public function sync(Integration $i, ?int $days = null): array
    {
        $client = MetaAdsClient::for($i);
        if (! $client->configured()) {
            throw new MetaException('Ad account ID and access token are required.');
        }
        $acct = $client->verify();
        $currency = strtoupper((string) ($acct['currency'] ?: 'USD'));
        $structure = $this->structure($client, $i, $currency);

        $tz = $acct['timezone'] ?: 'UTC';
        $days ??= $i->cfg('last_spend_sync_at') ? 3 : (int) ($i->cfg('initial_days') ?: 30); // recent days restate as Meta finalises attribution
        $until = CarbonImmutable::now($tz);
        $since = $until->subDays(max(1, min(90, $days)) - 1);
        $insights = $this->insights($client, $since->toDateString(), $until->toDateString(), $currency);

        $i->config = array_merge($i->config ?? [], [
            'account_name' => $acct['account_name'], 'account_currency' => $currency, 'account_timezone' => $tz,
            'last_spend_sync_at' => now()->toIso8601ZuluString(), 'last_sync_range' => [$since->toDateString(), $until->toDateString()],
        ]);
        $i->save();
        $i->markSuccess();

        return $structure + $insights + ['currency' => $currency, 'range' => [$since->toDateString(), $until->toDateString()]];
    }

    private function structure(MetaAdsClient $client, Integration $i, string $currency): array
    {
        $acct = $client->accountId();
        $map = [];
        $n = ['campaigns' => 0, 'ad_sets' => 0, 'ads' => 0];
        foreach ($client->campaigns() as $c) {
            $map[$c['id']] = $this->upsertCampaign($c, $acct, $currency)->id;
            $n['campaigns']++;
        }
        $sets = [];
        foreach ($client->adSets() as $s) {
            $cid = $map[$s['campaign_id'] ?? ''] ?? null;
            if (! $cid) {
                continue;
            }
            $set = AdSet::updateOrCreate(['campaign_id' => $cid, 'external_id' => $s['id']], ['name' => mb_substr($s['name'] ?? $s['id'], 0, 191), 'status' => $this->status($s)]);
            $sets[$s['id']] = $set->id;
            $n['ad_sets']++;
        }
        foreach ($client->ads() as $a) {
            $cid = $map[$a['campaign_id'] ?? ''] ?? null;
            if (! $cid) {
                continue;
            }
            Ad::updateOrCreate(['campaign_id' => $cid, 'external_id' => $a['id']],
                ['name' => mb_substr($a['name'] ?? $a['id'], 0, 191), 'ad_set_id' => $sets[$a['adset_id'] ?? ''] ?? null, 'status' => $this->status($a)]);
            $n['ads']++;
        }

        return $n;
    }

    public function upsertCampaign(array $c, ?string $acct, string $currency): Campaign
    {
        $attrs = [
            'name' => mb_substr($c['name'] ?? $c['id'], 0, 191), 'objective' => isset($c['objective']) ? mb_substr($c['objective'], 0, 60) : null,
            'status' => $this->status($c), 'currency' => $currency, 'data_source' => 'meta_api', 'ad_account_id' => $acct, 'last_synced_at' => now(),
            'starts_on' => isset($c['start_time']) ? substr($c['start_time'], 0, 10) : null,
            'ends_on' => isset($c['stop_time']) ? substr($c['stop_time'], 0, 10) : null,
        ];
        $campaign = Campaign::where('platform', 'meta')->where('external_id', $c['id'])->first();
        if (! $campaign) {
            // Link a manually entered/imported campaign of the same name instead of duplicating it,
            // so leads already attributed to it keep counting.
            $campaign = Campaign::whereNull('external_id')->whereIn('platform', ['meta', 'facebook', 'instagram'])
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($attrs['name']))])->first();
        }
        $campaign ??= new Campaign;
        $campaign->fill($attrs + ['platform' => 'meta', 'external_id' => $c['id']])->save();

        return $campaign;
    }

    private function insights(MetaAdsClient $client, string $since, string $until, string $currency): array
    {
        $rows = $client->dailyCampaignInsights($since, $until);
        $n = ['spend_days' => 0, 'event_rows' => 0, 'spend_total' => 0.0];
        DB::transaction(function () use ($rows, $currency, &$n) {
            foreach ($rows as $r) {
                $campaign = Campaign::where('platform', 'meta')->where('external_id', $r['campaign_id'] ?? '')->first()
                    ?? $this->upsertCampaign(['id' => $r['campaign_id'], 'name' => $r['campaign_name'] ?? $r['campaign_id']], null, $currency);
                $date = $r['date_start'];
                $cur = strtoupper((string) ($r['account_currency'] ?? $currency));
                CampaignSpend::updateOrCreate(['campaign_id' => $campaign->id, 'spend_date' => $date, 'data_source' => 'meta_api'],
                    ['amount' => round((float) ($r['spend'] ?? 0), 2), 'currency' => $cur]);
                $n['spend_days']++;
                $n['spend_total'] += (float) ($r['spend'] ?? 0);
                foreach ((array) ($r['actions'] ?? []) as $a) {
                    $type = self::ACTIONS[$a['action_type'] ?? ''] ?? null;
                    if (! $type) {
                        continue;
                    }
                    CampaignEvent::updateOrCreate(['data_source' => 'meta_api', 'external_event_id' => "ins:{$campaign->external_id}:$date:$type"],
                        ['campaign_id' => $campaign->id, 'event_type' => $type, 'event_date' => $date, 'event_count' => (int) round((float) ($a['value'] ?? 0))]);
                    $n['event_rows']++;
                }
            }
        });
        $n['spend_total'] = round($n['spend_total'], 2);

        return $n;
    }

    private function status(array $o): string
    {
        $s = strtoupper((string) ($o['effective_status'] ?? $o['status'] ?? ''));

        return match (true) {
            $s === 'ACTIVE' => 'active',
            in_array($s, ['ARCHIVED', 'DELETED', 'COMPLETED'], true) => 'ended',
            default => 'paused',
        };
    }
}
