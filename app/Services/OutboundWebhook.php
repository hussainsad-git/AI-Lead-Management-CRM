<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Signed outbound webhooks. Headers: X-LeadFlow-Event, X-LeadFlow-Delivery (unique id), X-LeadFlow-Timestamp,
 * X-LeadFlow-Signature = sha256 HMAC over "<timestamp>.<raw body>" with the rule's secret (empty secret → no signature).
 * Receivers should reject timestamps older than 5 minutes and compare signatures in constant time.
 */
class OutboundWebhook
{
    public function send(string $url, string $secret, string $event, array $payload): string
    {
        $body = json_encode(['event' => $event, 'sent_at' => now()->toIso8601ZuluString()] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = (string) time();
        $delivery = (string) \Illuminate\Support\Str::uuid();
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'LeadFlow-Webhooks/1.0', 'X-LeadFlow-Event' => $event, 'X-LeadFlow-Delivery' => $delivery, 'X-LeadFlow-Timestamp' => $ts];
        if ($secret !== '') {
            $headers['X-LeadFlow-Signature'] = 'sha256='.hash_hmac('sha256', $ts.'.'.$body, $secret);
        }
        try {
            $r = Http::withHeaders($headers)->timeout(10)->connectTimeout(5)->withOptions(['allow_redirects' => false])
                ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response->status() >= 500), throw: false)
                ->withBody($body, 'application/json')->post($url);
        } catch (ConnectionException $e) {
            throw new DomainRuleException('Webhook unreachable: '.mb_substr($e->getMessage(), 0, 150), 'webhook_failed', 502);
        }
        if (! $r->successful()) {
            throw new DomainRuleException('Webhook responded HTTP '.$r->status(), 'webhook_failed', 502);
        }

        return 'HTTP '.$r->status().' · delivery '.substr($delivery, 0, 8);
    }
}
