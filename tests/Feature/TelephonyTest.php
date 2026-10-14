<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Integration;
use App\Models\Notification;
use App\Models\TelephonyCall;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelephonyTest extends TestCase
{
    // Dummy SID assembled at runtime so secret scanners don't flag the test file.
    private const SID = 'AC'.'0123456789abcdef'.'0123456789abcdef';

    private function connected($org): Integration
    {
        return $this->inOrg($org, fn () => Integration::create(['provider' => 'twilio_voice', 'status' => 'connected', 'webhook_key' => Str::random(40),
            'config' => ['account_sid' => self::SID, 'from_number' => '+15005550006'], 'credentials' => ['auth_token' => 'secret-token']]));
    }

    private function sign(Integration $i, string $url, array $params): string
    {
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }

        return base64_encode(hash_hmac('sha1', $data, 'secret-token', true));
    }

    private function twilioPost(Integration $i, string $path, array $params, ?string $sig = null)
    {
        $url = url('/webhooks/telephony/'.$i->webhook_key.'/'.$path);

        return $this->withHeaders(['X-Twilio-Signature' => $sig ?? $this->sign($i, $url, $params)])->post($url, $params);
    }

    public function test_dial_creates_agent_leg_and_callbacks_drive_status_activity_and_notification(): void
    {
        $org = $this->makeOrg();
        $rep = $this->makeUser($org, 'sales_rep', ['phone' => '0301 2345678']);
        $i = $this->connected($org);
        $lead = $this->createLead($org, $rep, ['contact' => ['name' => 'Ali', 'phone' => '03001234567']]);
        Http::fake(['api.twilio.com/2010-04-01/Accounts/'.self::SID.'/Calls.json' => Http::response(['sid' => 'CA123', 'status' => 'queued'], 201)]);

        $show = $this->api($rep, 'GET', 'leads/'.$lead['id'])->assertOk()->json('data.telephony');
        $this->assertTrue($show['available']);
        $r = $this->api($rep, 'POST', 'leads/'.$lead['id'].'/dial')->assertStatus(202);
        $callId = $r->json('data.id');
        Http::assertSent(function ($req) use ($i) {
            $p = $req->data();

            return $req->method() === 'POST' && $p['To'] === '+923012345678' && $p['From'] === '+15005550006'
                && str_contains($p['Url'], '/webhooks/telephony/'.$i->webhook_key.'/twiml/') && str_contains($p['StatusCallback'], '/status/')
                && $req->hasHeader('Authorization');
        });
        $call = TelephonyCall::withoutGlobalScopes()->find($callId);
        $this->assertSame('CA123', $call->provider_sid);
        $this->assertSame('+923001234567', $call->to_number);
        // a second dial while one is open is refused
        $this->api($rep, 'POST', 'leads/'.$lead['id'].'/dial')->assertStatus(422)->assertJsonPath('error.code', 'call_in_progress');

        // TwiML fetch (signed) dials the customer with the Twilio number as caller ID, no recording by default
        $xml = $this->twilioPost($i, "twiml/$callId", ['CallSid' => 'CA123', 'CallStatus' => 'in-progress'])->assertOk()->assertHeader('Content-Type', 'text/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('<Number>+923001234567</Number>', $xml);
        $this->assertStringContainsString('callerId="+15005550006"', $xml);
        $this->assertStringNotContainsString('record=', $xml);

        $this->twilioPost($i, "status/$callId", ['CallSid' => 'CA123', 'CallStatus' => 'ringing'])->assertNoContent();
        $this->twilioPost($i, "status/$callId", ['CallSid' => 'CA123', 'CallStatus' => 'in-progress'])->assertNoContent();
        $this->assertSame('in-progress', $call->fresh()->status);
        $this->assertNotNull($call->fresh()->answered_at);
        // out-of-order "ringing" after "in-progress" must not regress
        $this->twilioPost($i, "status/$callId", ['CallSid' => 'CA123', 'CallStatus' => 'ringing'])->assertNoContent();
        $this->assertSame('in-progress', $call->fresh()->status);

        $this->twilioPost($i, "dial-status/$callId", ['DialCallStatus' => 'completed', 'DialCallDuration' => '95', 'DialCallSid' => 'CA456'])->assertOk();
        $this->twilioPost($i, "status/$callId", ['CallSid' => 'CA123', 'CallStatus' => 'completed', 'CallDuration' => '110'])->assertNoContent();
        $call->refresh();
        $this->assertSame('completed', $call->status);
        $this->assertSame(110, $call->duration_seconds);
        $this->assertSame(95, $call->talk_seconds);
        $this->assertSame('completed', $call->lead_leg_status);

        $acts = $this->inOrg($org, fn () => Activity::where('lead_id', $lead['id'])->whereIn('type', ['call_placed', 'call_ended'])->orderBy('id')->pluck('type')->all());
        $this->assertSame(['call_placed', 'call_ended'], $acts);
        $this->assertTrue($this->inOrg($org, fn () => Notification::where('user_id', $rep->id)->where('dedupe_key', 'call-outcome:'.$callId)->exists()));
        // duplicate "completed" delivery → no second activity / notification
        $this->twilioPost($i, "status/$callId", ['CallSid' => 'CA123', 'CallStatus' => 'completed', 'CallDuration' => '110'])->assertNoContent();
        $this->assertSame(1, $this->inOrg($org, fn () => Activity::where('lead_id', $lead['id'])->where('type', 'call_ended')->count()));

        // logging the outcome against the provider call pre-fills the measured talk time and marks it logged
        $outcome = $this->inOrg($org, fn () => \App\Models\CallOutcome::where('key', 'answered')->first());
        $this->api($rep, 'POST', 'leads/'.$lead['id'].'/calls', ['call_outcome_id' => $outcome->id, 'telephony_call_id' => $callId])->assertCreated();
        $logged = $this->inOrg($org, fn () => Activity::where('lead_id', $lead['id'])->where('type', 'call_logged')->first());
        $this->assertSame(95, $logged->meta['duration_seconds']);
        $this->assertSame('twilio', $logged->meta['duration_source']);
        $this->assertTrue($call->fresh()->outcome_logged);
        $this->api($rep, 'GET', 'leads/'.$lead['id'].'/telephony-calls')->assertOk()->assertJsonPath('data.0.talk_seconds', 95);
    }

    public function test_callbacks_require_a_valid_twilio_signature_and_matching_tenant(): void
    {
        $org = $this->makeOrg();
        $rep = $this->makeUser($org, 'sales_rep', ['phone' => '03012345678']);
        $i = $this->connected($org);
        $lead = $this->createLead($org, $rep, ['contact' => ['phone' => '03001234567']]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA1', 'status' => 'queued'], 201)]);
        $callId = $this->api($rep, 'POST', 'leads/'.$lead['id'].'/dial')->json('data.id');

        $this->twilioPost($i, "status/$callId", ['CallStatus' => 'completed'], 'bad')->assertForbidden();
        $this->twilioPost($i, "status/$callId", ['CallStatus' => 'completed'], base64_encode(hash_hmac('sha1', 'x', 'wrong', true)))->assertForbidden();
        $this->post('/webhooks/telephony/'.$i->webhook_key."/status/$callId", ['CallStatus' => 'completed'])->assertForbidden(); // no header
        $this->assertSame('queued', TelephonyCall::withoutGlobalScopes()->find($callId)->status);

        // another business with its own Twilio cannot touch this call
        $other = $this->makeOrg('Other');
        $i2 = $this->connected($other);
        $this->twilioPost($i2, "status/$callId", ['CallStatus' => 'completed'])->assertNotFound();
        $this->post('/webhooks/telephony/'.str_repeat('z', 40)."/status/$callId", [])->assertNotFound();
    }

    public function test_dial_is_refused_honestly_when_not_connected_opted_out_or_agent_has_no_phone(): void
    {
        $org = $this->makeOrg();
        $rep = $this->makeUser($org, 'sales_rep');
        $lead = $this->createLead($org, $rep, ['contact' => ['phone' => '03001234567']]);
        $mode = 'ok';
        Http::fake(function () use (&$mode) { return $mode === 'ok' ? Http::response(['sid' => 'CA1', 'status' => 'queued'], 201) : Http::response(['code' => 21215, 'message' => 'Account not authorized to call +92…'], 400); });

        $this->assertSame('telephony_not_connected', $this->api($rep, 'GET', 'leads/'.$lead['id'])->json('data.telephony.reason'));
        $this->api($rep, 'POST', 'leads/'.$lead['id'].'/dial')->assertStatus(422)->assertJsonPath('error.code', 'telephony_not_connected');

        $i = $this->connected($org);
        $this->assertSame('agent_phone_missing', $this->api($rep, 'GET', 'leads/'.$lead['id'])->json('data.telephony.reason'));
        $this->api($rep, 'POST', 'leads/'.$lead['id'].'/dial')->assertStatus(422)->assertJsonPath('error.code', 'agent_phone_missing');

        $rep->forceFill(['phone' => '03012345678'])->save();
        $this->inOrg($org, fn () => app(\App\Services\ConsentService::class)->optOut(\App\Models\Contact::find($lead['contact']['id']), 'phone', 'asked'));
        $this->api($rep, 'POST', 'leads/'.$lead['id'].'/dial')->assertStatus(422)->assertJsonPath('error.code', 'suppressed');
        Http::assertNothingSent();

        // provider failure is recorded, never shown as success
        $lead2 = $this->createLead($org, $rep, ['contact' => ['phone' => '03009876543']]);
        $mode = 'fail';
        $this->api($rep, 'POST', 'leads/'.$lead2['id'].'/dial')->assertStatus(502)->assertJsonPath('error.code', 'telephony_failed');
        $c = TelephonyCall::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('failed', $c->status);
        $this->assertStringContainsString('21215', $c->error);
        $this->assertStringContainsString('21215', $i->fresh()->last_error);
        // another rep cannot see / dial a lead outside their visibility
        $rep2 = $this->makeUser($org, 'sales_rep', ['phone' => '03012345679']);
        $this->api($rep2, 'POST', 'leads/'.$lead2['id'].'/dial')->assertNotFound();
    }

    public function test_integration_test_connection_uses_account_endpoint_and_reports_errors(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->api($admin, 'PUT', 'integrations/twilio_voice', ['config' => ['account_sid' => self::SID, 'from_number' => '+15005550006'], 'secrets' => ['auth_token' => 'tok']])->assertOk();
        $mode = 'ok';
        Http::fake(function () use (&$mode) { return $mode === 'ok' ? Http::response(['friendly_name' => 'Demo Realty', 'status' => 'active', 'type' => 'Full']) : Http::response(['code' => 20003, 'message' => 'Authenticate'], 401); });
        $r = $this->api($admin, 'POST', 'integrations/twilio_voice/test')->assertOk();
        $this->assertSame('connected', $r->json('data.status'));
        $this->assertSame('Demo Realty', $r->json('data.integration.config.account_name'));
        $this->assertFalse(str_contains(json_encode($r->json()), '"tok"'));

        $mode = 'fail';
        $this->api($admin, 'POST', 'integrations/twilio_voice/test')->assertStatus(422)->assertJsonPath('error.code', 'connection_failed');
        $this->assertSame('error', Integration::withoutGlobalScopes()->where('provider', 'twilio_voice')->first()->status);
        $this->api($admin, 'PUT', 'integrations/twilio_voice', ['config' => ['account_sid' => 'nope']])->assertStatus(422);
    }
}
