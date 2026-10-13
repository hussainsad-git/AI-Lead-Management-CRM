<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMetaLeadgen;
use App\Models\Integration;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Page "leadgen" webhook for Meta Lead Ads. Same verification model as the WhatsApp webhook. */
class MetaWebhookController extends Controller
{
    private function integration(string $key): Integration
    {
        $i = Integration::withoutGlobalScopes()->where('provider', 'meta_marketing')->where('webhook_key', $key)->first();
        abort_unless($i && $i->status !== 'disabled', 404);

        return $i;
    }

    public function verify(Request $request, string $key)
    {
        $i = $this->integration($key);
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $expected = (string) $i->secret('verify_token');
        if ($request->query('hub_mode', $request->query('hub.mode')) === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            return response((string) $request->query('hub_challenge', $request->query('hub.challenge', '')), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request, string $key)
    {
        $i = $this->integration($key);
        $raw = $request->getContent();
        $secret = (string) $i->secret('app_secret');
        $sig = (string) $request->header('X-Hub-Signature-256', '');
        if ($secret === '' || ! str_starts_with($sig, 'sha256=') || ! hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $sig)) {
            Log::warning('Meta leadgen webhook signature rejected', ['integration' => $i->id]);

            return response('Invalid signature', 401);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload) || ($payload['object'] ?? null) !== 'page') {
            return response('Ignored', 200);
        }
        $event = WebhookEvent::firstOrCreate(
            ['provider' => 'meta_leadgen', 'event_key' => hash('sha256', $raw)],
            ['organization_id' => $i->organization_id, 'integration_id' => $i->id, 'payload' => $payload, 'status' => 'received']
        );
        if ($event->wasRecentlyCreated) {
            $i->forceFill(['last_webhook_at' => now()])->saveQuietly();
            ProcessMetaLeadgen::dispatch($event->id);
        }

        return response('OK', 200);
    }
}
