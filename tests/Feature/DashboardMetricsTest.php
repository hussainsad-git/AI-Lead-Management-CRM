<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignSpend;
use App\Models\LeadStatus;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    public function test_ad_metrics_unavailable_without_spend_and_computed_with_it(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->api($admin, 'GET', 'dashboard?range=last_7_days')->assertOk()
            ->assertJsonPath('data.advertising.status', 'unavailable')
            ->assertJsonMissingPath('data.advertising.cost_per_lead');

        $campaign = $this->inOrg($org, fn () => Campaign::create(['platform' => 'facebook', 'name' => 'C1', 'currency' => 'PKR']));
        $a = $this->createLead($org, $admin, ['attribution' => ['campaign_id' => $campaign->id]]);
        $this->createLead($org, $admin, ['attribution' => ['campaign_id' => $campaign->id]]);
        $this->createLead($org, $admin); // unattributed, excluded from CPL
        $this->inOrg($org, fn () => CampaignSpend::create(['campaign_id' => $campaign->id, 'spend_date' => now('Asia/Karachi')->toDateString(), 'amount' => 1000, 'currency' => 'PKR']));
        $this->inOrg($org, fn () => CampaignSpend::create(['campaign_id' => $campaign->id, 'spend_date' => now('Asia/Karachi')->subDay()->toDateString(), 'amount' => 999, 'currency' => 'USD', 'data_source' => 'import']));
        $this->api($admin, 'POST', "leads/{$a['id']}/status", ['lead_status_id' => $this->lookup($org, LeadStatus::class, 'won')]);

        $d = $this->api($admin, 'GET', 'dashboard?range=last_7_days')->assertOk()->json('data');
        $this->assertSame('available', $d['advertising']['status']);
        $this->assertEquals(1000, $d['advertising']['spend']);
        $this->assertSame(2, $d['advertising']['attributed_leads']);
        $this->assertEquals(500, $d['advertising']['cost_per_lead']);
        $this->assertEquals(1000, $d['advertising']['cost_per_qualified_lead']);
        $this->assertEquals(1000, $d['advertising']['customer_acquisition_cost']);
        $this->assertSame(1, $d['advertising']['excluded_foreign_currency_rows']);
        $this->assertSame(3, $d['summary']['new_leads_in_range']);
        $this->assertEquals(33.3, $d['summary']['conversion_rate']);
    }

    public function test_rep_dashboard_is_scoped_and_ad_metrics_restricted(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $rep = $this->makeUser($org, 'sales_rep');
        $this->createLead($org, $admin, ['assigned_user_id' => $rep->id]);
        $this->createLead($org, $admin);
        $d = $this->api($rep, 'GET', 'dashboard')->json('data');
        $this->assertSame('own', $d['scope']);
        $this->assertSame(1, $d['summary']['total_leads']);
        $this->assertSame('restricted', $d['advertising']['status']);
    }

    public function test_campaign_rename_preserves_attribution_snapshot(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $campaign = $this->inOrg($org, fn () => Campaign::create(['platform' => 'instagram', 'name' => 'Original name', 'currency' => 'PKR']));
        $lead = $this->createLead($org, $admin, ['attribution' => ['campaign_id' => $campaign->id]]);
        $this->api($admin, 'PATCH', "campaigns/{$campaign->id}", ['name' => 'Renamed'])->assertOk();
        $this->api($admin, 'GET', "leads/{$lead['id']}")->assertJsonPath('data.lead.attribution.campaign', 'Original name');
    }

    public function test_invalid_date_range_returns_clean_error(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->api($admin, 'GET', 'dashboard?range=custom&from=2026-10-10&to=2026-10-01')->assertStatus(422)->assertJsonPath('error.code', 'invalid_date_range');
    }
}
