<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignSpend;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadFormSubmission;
use App\Models\Organization;
use App\Models\WebhookEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MetaIntegrationTest extends TestCase
{
    private const SECRET = 'meta-app-secret';

    private array $graphLeads = [];

    private function connect(Organization $org, array $config = []): Integration
    {
        return $this->inOrg($org, fn () => Integration::create(['provider' => 'meta_marketing', 'status' => 'connected', 'webhook_key' => Str::random(40),
            'config' => ['ad_account_id' => '123456789', 'page_id' => '555', 'api_version' => 'v25.0', 'lead_ads_enabled' => true] + $config,
            'credentials' => ['access_token' => 'EAAG-sys', 'app_secret' => self::SECRET, 'verify_token' => 'verify-me']]));
    }

    private function leadRecord(string $id, array $fields, array $extra = []): array
    {
        return ['id' => $id, 'created_time' => '2026-10-10T08:30:00+0000', 'form_id' => '900', 'campaign_id' => 'C1', 'campaign_name' => 'Plots Oct',
            'adset_id' => 'S1', 'adset_name' => 'Lahore 25-45', 'ad_id' => 'A1', 'ad_name' => 'Video 1', 'platform' => 'fb',
            'field_data' => collect($fields)->map(fn ($v, $k) => ['name' => $k, 'values' => [$v]])->values()->all()] + $extra;
    }

    private function fakeGraph(array $leads = [], array $insights = []): void
    {
        $this->graphLeads = $leads;
        Http::fake(function (Request $r) use ($insights) {
            $leads = $this->graphLeads;
            $u = $r->url();
            $path = parse_url($u, PHP_URL_PATH);
            return match (true) {
                str_ends_with($path, '/me') => Http::response(['id' => '1', 'name' => 'System User']),
                str_ends_with($path, '/act_123456789') => Http::response(['name' => 'Acme Ads', 'currency' => 'PKR', 'timezone_name' => 'Asia/Karachi', 'account_status' => 1]),
                str_ends_with($path, '/555') => Http::response(['id' => '555', 'name' => 'Acme Page']),
                str_ends_with($path, '/campaigns') => Http::response(['data' => [['id' => 'C1', 'name' => 'Plots Oct', 'objective' => 'OUTCOME_LEADS', 'effective_status' => 'ACTIVE', 'start_time' => '2026-10-01T00:00:00+0500']],
                    'paging' => ['cursors' => ['after' => 'x']]]),
                str_ends_with($path, '/adsets') => Http::response(['data' => [['id' => 'S1', 'name' => 'Lahore 25-45', 'campaign_id' => 'C1', 'effective_status' => 'ACTIVE']]]),
                str_ends_with($path, '/ads') => Http::response(['data' => [['id' => 'A1', 'name' => 'Video 1', 'adset_id' => 'S1', 'campaign_id' => 'C1', 'effective_status' => 'PAUSED']]]),
                str_ends_with($path, '/insights') => Http::response(['data' => $insights]),
                str_ends_with($path, '/leadgen_forms') => Http::response(['data' => [['id' => '900', 'name' => 'Plot enquiry', 'status' => 'ACTIVE', 'leads_count' => count($leads)]]]),
                str_ends_with($path, '/900/leads') => Http::response(['data' => array_values($leads)]),
                str_ends_with($path, '/subscribed_apps') => Http::response(['success' => true]),
                preg_match('#/(\d+)$#', $path, $m) && isset($leads[$m[1]]) => Http::response($leads[$m[1]]),
                default => Http::response(['error' => ['message' => 'Unsupported get request: '.$path, 'code' => 100]], 400),
            };
        });
    }

    private function deliver(Integration $i, array $payload, ?string $secret = null)
    {
        $raw = json_encode($payload);

        return $this->call('POST', '/webhooks/meta/'.$i->webhook_key, [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret ?? self::SECRET)], $raw);
    }

    private function leadgenPayload(string $leadgenId): array
    {
        return ['object' => 'page', 'entry' => [['id' => '555', 'time' => 1760000000, 'changes' => [['field' => 'leadgen',
            'value' => ['leadgen_id' => $leadgenId, 'page_id' => '555', 'form_id' => '900', 'ad_id' => 'A1', 'adgroup_id' => 'A1', 'created_time' => 1760000000]]]]]];
    }

    public function test_sync_imports_structure_spend_and_aggregate_events_and_api_spend_wins_over_manual(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $i = $this->connect($org);
        // Manual spend already entered for the same campaign (by name) and day.
        $manual = $this->inOrg($org, fn () => Campaign::create(['platform' => 'meta', 'name' => 'Plots Oct', 'currency' => 'PKR', 'data_source' => 'manual']));
        $this->inOrg($org, fn () => CampaignSpend::create(['campaign_id' => $manual->id, 'spend_date' => '2026-10-11', 'amount' => 999, 'currency' => 'PKR', 'data_source' => 'manual']));
        $this->inOrg($org, fn () => CampaignSpend::create(['campaign_id' => $manual->id, 'spend_date' => '2026-10-09', 'amount' => 400, 'currency' => 'PKR', 'data_source' => 'manual']));

        $this->fakeGraph([], [
            ['campaign_id' => 'C1', 'campaign_name' => 'Plots Oct', 'spend' => '1500.50', 'date_start' => '2026-10-11', 'date_stop' => '2026-10-11', 'account_currency' => 'PKR',
                'actions' => [['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '12'], ['action_type' => 'link_click', 'value' => '80'], ['action_type' => 'post_engagement', 'value' => '300']]],
            ['campaign_id' => 'C1', 'campaign_name' => 'Plots Oct', 'spend' => '1200', 'date_start' => '2026-10-12', 'date_stop' => '2026-10-12', 'account_currency' => 'PKR', 'actions' => []],
        ]);
        $r = $this->api($admin, 'POST', 'integrations/meta_marketing/sync', ['days' => 7])->assertOk();
        $this->assertSame(1, $r->json('data.campaigns'));
        $this->assertSame(2, $r->json('data.spend_days'));

        $this->inOrg($org, function () use ($manual) {
            $this->assertSame(1, Campaign::count(), 'manual campaign linked by name, not duplicated');
            $c = Campaign::first();
            $this->assertSame('C1', $c->external_id);
            $this->assertSame('meta_api', $c->data_source);
            $this->assertSame($manual->id, $c->id);
            $this->assertSame(1, $c->adSets()->count());
            $this->assertSame('paused', \App\Models\Ad::first()->status);
            $this->assertSame(4, CampaignSpend::count());
            // Effective spend: 10-09 manual 400 + 10-11 API 1500.50 (manual 999 ignored) + 10-12 API 1200
            $this->assertEquals(3100.50, (float) CampaignSpend::effective()->sum('amount'));
            $this->assertSame(['conversation_started' => 12, 'link_click' => 80], CampaignEvent::pluck('event_count', 'event_type')->map(fn ($v) => (int) $v)->sortKeys()->all());
        });
        // Re-running the same window is idempotent.
        $this->api($admin, 'POST', 'integrations/meta_marketing/sync', ['days' => 7])->assertOk();
        $this->inOrg($org, fn () => $this->assertSame(4, CampaignSpend::count()));
        $this->api($admin, 'GET', 'campaigns')->assertOk()->assertJsonPath('data.0.spend', 3100.5);
    }

    public function test_leadgen_webhook_creates_lead_with_attribution_exactly_once(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $i = $this->connect($org);
        $this->fakeGraph(['700001' => $this->leadRecord('700001', ['full_name' => 'Ayesha Khan', 'phone_number' => '+923001234567', 'email' => 'ayesha@example.com', 'city' => 'Lahore', 'budget' => '50 lakh', 'which_project?' => 'Phase 9'],
            ['custom_disclaimer_responses' => [['checkbox_key' => 'marketing', 'is_checked' => true]]])]);

        // Handshake
        $this->get('/webhooks/meta/'.$i->webhook_key.'?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=abc123')->assertOk()->assertSee('abc123');
        $this->get('/webhooks/meta/'.$i->webhook_key.'?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=abc123')->assertForbidden();
        // Bad signature
        $this->deliver($i, $this->leadgenPayload('700001'), 'wrong')->assertStatus(401);
        $this->inOrg($org, fn () => $this->assertSame(0, Lead::count()));

        $this->deliver($i, $this->leadgenPayload('700001'))->assertOk();
        $this->deliver($i, $this->leadgenPayload('700001'))->assertOk(); // Meta retry
        $this->inOrg($org, function () {
            $this->assertSame(1, Lead::count());
            $this->assertSame(1, WebhookEvent::where('provider', 'meta_leadgen')->count());
            $lead = Lead::with('contact', 'firstTouch', 'source')->first();
            $this->assertSame('Ayesha Khan', $lead->contact->name);
            $this->assertSame('+923001234567', $lead->contact->phone_e164);
            $this->assertSame('Lahore', $lead->contact->city);
            $this->assertSame('700001', $lead->external_lead_id);
            $this->assertSame('meta_instant_form', $lead->source->key);
            $this->assertSame('complete', $lead->firstTouch->completeness);
            $this->assertSame('meta_leadgen', $lead->firstTouch->captured_via);
            $this->assertSame('facebook', $lead->firstTouch->platform);
            $this->assertSame('Plots Oct', $lead->firstTouch->campaign_name_snapshot);
            $this->assertSame('A1', $lead->firstTouch->external_ad_id);
            $this->assertStringContainsString('Budget: 50 lakh', $lead->source_notes);
            $this->assertStringContainsString('Consent checkbox "marketing": checked', $lead->source_notes);
            $this->assertSame('2026-10-10 08:30:00', $lead->created_at->format('Y-m-d H:i:s'));
            $this->assertSame('created', LeadFormSubmission::first()->outcome);
            $this->assertSame(1, \App\Models\LeadForm::first()->leads_received);
        });

        // A second, different leadgen id for the same person attaches to the open lead rather than duplicating.
        $this->graphLeads['700002'] = $this->leadRecord('700002', ['full_name' => 'Ayesha Khan', 'phone_number' => '03001234567']);
        $this->deliver($i, $this->leadgenPayload('700002'))->assertOk();
        $this->inOrg($org, function () {
            $this->assertSame(1, Lead::count());
            $this->assertSame('existing_lead', LeadFormSubmission::where('external_lead_id', '700002')->value('outcome'));
            $this->assertTrue(\App\Models\Activity::where('type', 'form_submitted')->exists());
        });
    }

    public function test_submission_without_contact_details_never_fabricates_a_contact(): void
    {
        $org = $this->makeOrg();
        $i = $this->connect($org);
        $this->fakeGraph(['700009' => $this->leadRecord('700009', ['full_name' => 'Nobody', 'phone_number' => '12'])]);
        $this->deliver($i, $this->leadgenPayload('700009'))->assertOk();
        $this->inOrg($org, function () {
            $this->assertSame(0, Lead::count());
            $this->assertSame(0, \App\Models\Contact::count());
            $this->assertSame('no_contact', LeadFormSubmission::first()->outcome);
            $this->assertSame('processed', WebhookEvent::first()->status);
        });
    }

    public function test_backfill_recovers_missed_leads_and_expired_token_is_reported_honestly(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $i = $this->connect($org);
        $this->fakeGraph(['700005' => $this->leadRecord('700005', ['full_name' => 'Bilal', 'phone_number' => '03211234567']), '700006' => $this->leadRecord('700006', ['email' => 'only@example.com'])]);
        Artisan::call('meta:sync');
        $this->inOrg($org, function () {
            // Email-only submission is kept as a failed submission (org requires a phone), never a fabricated contact.
            $this->assertSame(1, Lead::count());
            $this->assertSame(1, LeadFormSubmission::where('received_via', 'backfill')->where('outcome', 'created')->count());
            $failed = LeadFormSubmission::where('external_lead_id', '700006')->first();
            $this->assertSame('failed', $failed->outcome);
            $this->assertStringContainsString('phone', $failed->error);
            $this->assertSame(0, \App\Models\Contact::whereNull('phone_e164')->count());
        });
        Artisan::call('meta:sync'); // idempotent; the failed one is retried, not duplicated
        $this->inOrg($org, fn () => $this->assertSame(1, Lead::count()));
    }

    public function test_expired_token_is_reported_honestly_and_never_leaked(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->connect($org);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Error validating access token: Session has expired', 'code' => 190]], 401)]);
        $this->api($admin, 'POST', 'integrations/meta_marketing/test')->assertStatus(422)->assertJsonPath('error.code', 'connection_failed');
        $st = $this->api($admin, 'GET', 'integrations/status')->assertOk()->json('data');
        $meta = collect($st)->firstWhere('key', 'meta_marketing');
        $this->assertSame('error', $meta['status']);
        $this->assertStringContainsString('expired', $meta['last_error']);
        $this->assertTrue($meta['secrets']['access_token']['set']);
        $this->assertStringNotContainsString('EAAG', json_encode($st));
    }

    public function test_meta_endpoints_require_integrations_permission_and_isolate_tenants(): void
    {
        $org = $this->makeOrg();
        $rep = $this->makeUser($org, 'sales_rep');
        $this->api($rep, 'POST', 'integrations/meta_marketing/sync')->assertForbidden();
        $this->api($rep, 'PUT', 'integrations/meta_marketing', ['config' => ['ad_account_id' => 'act_1']])->assertForbidden();

        $other = $this->makeOrg('Other');
        $i = $this->connect($other);
        $this->fakeGraph(['700001' => $this->leadRecord('700001', ['phone_number' => '03001234567'])]);
        $this->deliver($i, $this->leadgenPayload('700001'))->assertOk();
        $this->inOrg($org, fn () => $this->assertSame(0, Lead::count()));
        $this->inOrg($other, fn () => $this->assertSame(1, Lead::count()));
        $this->get('/webhooks/meta/'.Str::random(40))->assertNotFound();
    }
}
