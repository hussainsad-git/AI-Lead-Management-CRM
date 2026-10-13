<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\Meta\LeadAdsService;
use App\Services\Meta\MetaException;
use App\Services\Meta\MetaSyncService;
use App\Services\NotificationService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

/** Hourly: campaign structure + spend for every connected Meta account; Lead Ads backfill for missed webhooks. */
class MetaSync extends Command
{
    protected $signature = 'meta:sync {--days= : Spend days to (re)load, default 3 (30 on first run)} {--org= : Only this organization id}';

    protected $description = 'Sync Meta campaigns, spend, aggregate events and Instant Form leads';

    public function handle(MetaSyncService $sync, LeadAdsService $leadAds, CurrentOrganization $current, NotificationService $notify): int
    {
        $q = Integration::withoutGlobalScopes()->where('provider', 'meta_marketing')->whereIn('status', ['connected', 'error'])
            ->when($this->option('org'), fn ($q, $o) => $q->where('organization_id', (int) $o));
        foreach ($q->get() as $i) {
            $org = $i->organization;
            if (! $org || ! $org->is_active) {
                continue;
            }
            $current->runAs($org, function () use ($i, $sync, $leadAds, $notify) {
                try {
                    $s = $sync->sync($i, $this->option('days') ? (int) $this->option('days') : null);
                    $this->info("[{$i->organization_id}] campaigns {$s['campaigns']} · spend days {$s['spend_days']} ({$s['currency']} {$s['spend_total']})");
                    if ($i->cfg('page_id') && $i->cfg('lead_ads_enabled')) {
                        $since = $i->cfg('last_backfill_at') ? strtotime($i->cfg('last_backfill_at')) - 86400 : now()->subDays(7)->timestamp;
                        $b = $leadAds->backfill($i, $since);
                        $this->info("[{$i->organization_id}] forms {$b['forms']} · leads seen {$b['seen']} · created {$b['created']}");
                    }
                } catch (MetaException $e) {
                    $i->markError($e->getMessage());
                    $this->error("[{$i->organization_id}] ".$e->getMessage());
                    foreach ($notify->escalationTargets(null) as $uid) {
                        $notify->notify($uid, 'integration_error', 'Meta sync failed', $e->getMessage(), '/integrations', 'meta_sync:'.now()->format('YmdH'));
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
