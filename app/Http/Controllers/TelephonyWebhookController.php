<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Models\TelephonyCall;
use App\Services\Telephony\TelephonyService;
use App\Services\Telephony\TwilioClient;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;

/** Twilio callbacks: TwiML fetch, status, dial result, recording. Every request is signature-checked with the auth token. */
class TelephonyWebhookController extends Controller
{
    private function resolve(Request $request, string $key, int $callId): array
    {
        $i = Integration::withoutGlobalScopes()->where('provider', 'twilio_voice')->where('webhook_key', $key)->first();
        abort_unless($i && $i->status !== 'disabled', 404);
        abort_unless(TwilioClient::for($i)->validSignature($request->fullUrl(), $request->isMethod('POST') ? $request->post() : [], $request->header('X-Twilio-Signature')), 403);
        $call = TelephonyCall::withoutGlobalScopes()->where('organization_id', $i->organization_id)->find($callId);
        abort_unless($call, 404);
        $i->forceFill(['last_webhook_at' => now()])->saveQuietly();

        return [$i, $call];
    }

    private function inOrg(Integration $i, callable $fn)
    {
        return app(CurrentOrganization::class)->runAs($i->organization, $fn);
    }

    public function twiml(Request $request, string $key, int $call, TelephonyService $svc)
    {
        [$i, $c] = $this->resolve($request, $key, $call);

        return response($this->inOrg($i, fn () => $svc->twiml($i, $c)), 200)->header('Content-Type', 'text/xml');
    }

    public function status(Request $request, string $key, int $call, TelephonyService $svc)
    {
        [$i, $c] = $this->resolve($request, $key, $call);
        $this->inOrg($i, fn () => $svc->applyStatus($c, $request->post()));

        return response('', 204);
    }

    public function dialStatus(Request $request, string $key, int $call, TelephonyService $svc)
    {
        [$i, $c] = $this->resolve($request, $key, $call);

        return response($this->inOrg($i, fn () => $svc->applyDialStatus($c, $request->post())), 200)->header('Content-Type', 'text/xml');
    }

    public function recording(Request $request, string $key, int $call, TelephonyService $svc)
    {
        [$i, $c] = $this->resolve($request, $key, $call);
        $this->inOrg($i, fn () => $svc->applyRecording($c, $request->post()));

        return response('', 204);
    }
}
