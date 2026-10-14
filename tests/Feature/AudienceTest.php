<?php

namespace Tests\Feature;

use App\Models\AudienceSegment;
use App\Models\AudienceSegmentMembership;
use App\Models\AudienceSyncJob;
use App\Models\Contact;
use App\Models\Integration;
use App\Models\LeadStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AudienceTest extends TestCase
{
    private function def(array $rules, string $match = 'all'): array
    {
        return ['match' => 'all', 'groups' => [['match' => $match, 'rules' => $rules]]];
    }

    public function test_default_segments_are_seeded_and_dnc_exclusion_is_attached(): void
    {
        $org = $this->makeOrg();
        $this->inOrg($org, function () {
            $this->assertSame(12, AudienceSegment::count());
            $dnc = AudienceSegment::where('template_key', 'dnc_exclusions')->first();
            $this->assertSame('exclusion', $dnc->kind);
            $this->assertContains($dnc->id, AudienceSegment::where('template_key', 'customers')->first()->exclusion_segment_ids);
        });
    }

    public function test_segment_query_groups_and_eligibility_rules(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $a = $this->createLead($org, $admin, ['contact' => ['name' => 'A', 'phone' => '03001111111', 'city' => 'Lahore'], 'priority' => 'high']);
        $b = $this->createLead($org, $admin, ['contact' => ['name' => 'B', 'phone' => '03002222222', 'city' => 'Karachi'], 'priority' => 'low']);
        $c = $this->createLead($org, $admin, ['contact' => ['name' => 'C', 'phone' => '03003333333', 'city' => 'Lahore'], 'priority' => 'low']);
        $d = $this->createLead($org, $admin, ['contact' => ['name' => 'D', 'email' => 'd@example.com', 'phone' => '03004444444'], 'priority' => 'high']);
        // C opts out; D withdraws ads consent only.
        $this->api($admin, 'POST', "leads/{$c['id']}/status", ['lead_status_id' => $this->lookup($org, LeadStatus::class, 'do_not_contact'), 'reason' => 'asked'])->assertOk();
        $this->inOrg($org, fn () => app(\App\Services\ConsentService::class)->record(Contact::find($d['contact']['id']), 'ads_audience', 'withdrawn', 'manual_entry'));

        // (city contains lahore) OR (priority high) — grouped with ANY
        $res = $this->api($admin, 'POST', 'audiences', ['name' => 'Lahore or high', 'definition' => ['match' => 'any', 'groups' => [
            ['match' => 'all', 'rules' => [['field' => 'city', 'op' => 'contains', 'value' => 'lahore']]],
            ['match' => 'all', 'rules' => [['field' => 'priority', 'op' => 'in', 'value' => ['high']]]],
        ]]])->assertCreated();
        $seg = $res->json('data');
        $this->assertSame(3, $seg['last_count']);     // A, C, D match (B is Karachi + low)
        $this->assertSame(1, $seg['last_eligible']);  // C suppressed, D consent withdrawn
        $this->assertSame(1, $seg['skipped']['suppressed']);
        $this->assertSame(1, $seg['skipped']['consent']);

        $show = $this->api($admin, 'GET', 'audiences/'.$seg['id'])->assertOk()->json('data');
        $this->assertCount(1, $show['preview']);
        $this->assertSame('+923•••111', $show['preview'][0]['phone']); // masked in previews

        // Strict policy: nobody has explicit ads consent → 0 eligible
        $est = $this->api($admin, 'POST', 'audiences/estimate', ['name' => 'x', 'consent_policy' => 'granted', 'definition' => $this->def([['field' => 'priority', 'op' => 'in', 'value' => ['high']]])])->assertOk()->json('data');
        $this->assertSame(2, $est['members']);
        $this->assertSame(0, $est['eligible']);

        // Exclusion list removes B from a segment that would otherwise include everyone contactable
        $ex = $this->api($admin, 'POST', 'audiences', ['name' => 'Karachi', 'kind' => 'exclusion', 'definition' => $this->def([['field' => 'city', 'op' => 'contains', 'value' => 'karachi']])])->assertCreated()->json('data');
        $all = $this->api($admin, 'POST', 'audiences', ['name' => 'All open', 'exclusion_segment_ids' => [$ex['id']], 'definition' => $this->def([['field' => 'lifecycle', 'op' => 'in', 'value' => ['open']]])])->assertCreated()->json('data');
        $this->assertSame(3, $all['last_count']);
        $this->assertSame(1, $all['skipped']['excluded']);
        $this->api($admin, 'DELETE', 'audiences/'.$ex['id'])->assertStatus(409); // in use

        // Membership snapshot tracks leavers with a timeline event
        $this->api($admin, 'PATCH', "leads/{$a['id']}", ['priority' => 'low'])->assertOk();
        $this->inOrg($org, fn () => Contact::find($a['contact']['id'])->forceFill(['city' => 'Multan'])->save());
        $this->api($admin, 'POST', 'audiences/'.$seg['id'].'/refresh')->assertOk()->assertJsonPath('data.members', 2);
        $this->inOrg($org, function () use ($seg, $a) {
            $m = AudienceSegmentMembership::where('audience_segment_id', $seg['id'])->where('lead_id', $a['id'])->first();
            $this->assertNotNull($m->removed_at);
            $this->assertTrue(\App\Models\Activity::where('lead_id', $a['id'])->where('type', 'audience_removed')->exists());
        });
    }

    public function test_validation_rejects_empty_definitions_and_foreign_ids(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->api($admin, 'POST', 'audiences', ['name' => 'Everyone', 'definition' => ['groups' => []]])->assertStatus(422);
        $this->api($admin, 'POST', 'audiences', ['name' => 'Bad op', 'definition' => $this->def([['field' => 'city', 'op' => 'gte', 'value' => 1]])])->assertStatus(422);
        $other = $this->makeOrg('Other');
        $otherTag = $this->inOrg($other, fn () => \App\Models\Tag::create(['name' => 'vip']));
        $this->api($admin, 'POST', 'audiences', ['name' => 'Tag', 'definition' => $this->def([['field' => 'tag', 'op' => 'in', 'value' => [$otherTag->id]]])])->assertStatus(422);
        $rep = $this->makeUser($org, 'sales_rep');
        $this->api($rep, 'GET', 'audiences')->assertForbidden();
    }

    public function test_csv_export_is_audited_and_hashed_variant_contains_no_raw_pii(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->createLead($org, $admin, ['contact' => ['name' => '=cmd()', 'phone' => '03001234567', 'email' => 'Zed@Example.com']]);
        $seg = $this->api($admin, 'POST', 'audiences', ['name' => 'New', 'definition' => $this->def([['field' => 'created_days', 'op' => 'within', 'value' => 1]])])->assertCreated()->json('data');

        $raw = $this->actingAs($admin)->get('/api/v1/audiences/'.$seg['id'].'/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('+923001234567', $raw);
        $this->assertStringContainsString("'=cmd()", $raw); // formula neutralised
        $hashed = $this->actingAs($admin)->get('/api/v1/audiences/'.$seg['id'].'/export?hashed=1')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('923001234567', $hashed);
        $this->assertStringContainsString(hash('sha256', '923001234567'), $hashed);
        $this->assertStringContainsString(hash('sha256', 'zed@example.com'), $hashed);
        $this->inOrg($org, function () {
            $this->assertSame(2, AudienceSyncJob::where('kind', 'csv_export')->count());
            $this->assertSame(2, \App\Models\AuditLog::where('action', 'audience.exported')->count());
        });
        $analyst = $this->makeUser($org, 'analyst');
        $this->actingAs($analyst)->get('/api/v1/audiences/'.$seg['id'].'/export')->assertForbidden();
    }

    public function test_meta_sync_creates_audience_and_uploads_hashed_rows_in_a_replace_session(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->inOrg($org, fn () => Integration::create(['provider' => 'meta_marketing', 'status' => 'connected', 'webhook_key' => Str::random(40),
            'config' => ['ad_account_id' => '123456789', 'api_version' => 'v25.0'], 'credentials' => ['access_token' => 'EAAG-sys', 'app_secret' => 's', 'verify_token' => 'v']]));
        $this->createLead($org, $admin, ['contact' => ['name' => 'One', 'phone' => '03001234567']]);
        $this->createLead($org, $admin, ['contact' => ['name' => 'Two', 'email' => 'two@example.com', 'phone' => '03009876543']]);
        $dnc = $this->createLead($org, $admin, ['contact' => ['name' => 'Opted', 'phone' => '03005555555']]);
        $this->api($admin, 'POST', "leads/{$dnc['id']}/status", ['lead_status_id' => $this->lookup($org, LeadStatus::class, 'do_not_contact'), 'reason' => 'asked']);

        $calls = [];
        Http::fake(function (Request $r) use (&$calls) {
            $calls[] = $r;
            $path = parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/customaudiences') => Http::response(['id' => '2384000001']),
                str_ends_with($path, '/usersreplace') => Http::response(['audience_id' => '2384000001', 'session_id' => 1, 'num_received' => 2, 'num_invalid_entries' => 0]),
                str_ends_with($path, '/2384000001') => Http::response(['name' => 'LeadFlow · New', 'approximate_count_lower_bound' => 0, 'approximate_count_upper_bound' => 100,
                    'delivery_status' => ['code' => 300, 'description' => 'Too small'], 'operation_status' => ['code' => 200, 'description' => 'Normal']]),
                default => Http::response(['error' => ['message' => 'unexpected '.$path, 'code' => 100]], 400),
            };
        });

        $seg = $this->api($admin, 'POST', 'audiences', ['name' => 'New', 'definition' => $this->def([['field' => 'created_days', 'op' => 'within', 'value' => 1]])])->assertCreated()->json('data');
        $this->api($admin, 'POST', 'audiences/'.$seg['id'].'/sync')->assertStatus(202);
        $job = $this->inOrg($org, fn () => AudienceSyncJob::where('kind', 'meta_replace')->first());
        $this->assertSame('success', $job->status, (string) $job->error);
        $this->assertSame(3, $job->members);
        $this->assertSame(2, $job->eligible);
        $this->assertSame(2, $job->uploaded);
        $this->assertSame(1, $job->skipped['suppressed']);
        $this->assertSame('2384000001', $this->inOrg($org, fn () => AudienceSegment::find($seg['id'])->meta_audience_id));

        $replace = collect($calls)->first(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/usersreplace'));
        $this->assertNotNull($replace);
        $payload = json_decode($replace['payload'], true);
        $session = json_decode($replace['session'], true);
        $this->assertSame(['PHONE', 'EMAIL', 'COUNTRY'], $payload['schema']);
        $this->assertCount(2, $payload['data']);
        $this->assertTrue($session['last_batch_flag']);
        $this->assertSame(2, $session['estimated_num_total']);
        $body = $replace->body();
        $this->assertStringNotContainsString('923001234567', $body);
        $this->assertStringNotContainsString('two@example.com', $body);
        $this->assertStringContainsString(hash('sha256', '923001234567'), $body);
        $this->assertStringNotContainsString(hash('sha256', '923005555555'), $body); // opted out never uploaded

        $status = $this->api($admin, 'GET', 'audiences/meta-status')->assertOk()->json();
        $this->assertSame('Too small', $status['data'][0]['remote']['delivery']);
        $this->assertTrue($status['meta']['connected']);
    }

    public function test_meta_sync_failures_are_recorded_honestly(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $seg = $this->api($admin, 'POST', 'audiences', ['name' => 'New', 'definition' => $this->def([['field' => 'created_days', 'op' => 'within', 'value' => 1]])])->assertCreated()->json('data');
        $this->api($admin, 'POST', 'audiences/'.$seg['id'].'/sync')->assertStatus(422)->assertJsonPath('error.code', 'meta_not_connected');

        $this->inOrg($org, fn () => Integration::create(['provider' => 'meta_marketing', 'status' => 'connected', 'webhook_key' => Str::random(40),
            'config' => ['ad_account_id' => '123456789'], 'credentials' => ['access_token' => 'EAAG-sys', 'app_secret' => 's', 'verify_token' => 'v']]));
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Custom Audience Terms Of Service not accepted', 'code' => 2654]], 400)]);
        $this->api($admin, 'POST', 'audiences/'.$seg['id'].'/sync')->assertStatus(202);
        $job = $this->inOrg($org, fn () => AudienceSyncJob::where('kind', 'meta_replace')->first());
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('Custom Audience terms', $job->error);
        $this->assertNull($this->inOrg($org, fn () => AudienceSegment::find($seg['id'])->meta_audience_id));
        $this->assertTrue($this->inOrg($org, fn () => \App\Models\Notification::where('type', 'audience_sync')->where('user_id', $admin->id)->exists()));

        $dnc = $this->inOrg($org, fn () => AudienceSegment::where('template_key', 'dnc_exclusions')->first());
        $this->api($admin, 'POST', 'audiences/'.$dnc->id.'/sync')->assertStatus(422)->assertJsonPath('error.code', 'exclusion_segment');

        Artisan::call('audiences:sync');
        $this->assertSame(1, $this->inOrg($org, fn () => AudienceSyncJob::where('kind', 'meta_replace')->count())); // auto_sync off → no new job
    }
}
