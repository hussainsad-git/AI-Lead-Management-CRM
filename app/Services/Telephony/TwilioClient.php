<?php

namespace App\Services\Telephony;

use App\Models\Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Twilio Programmable Voice (REST API 2010-04-01), documented endpoints only:
 *   GET  /Accounts/{sid}.json        — verify credentials
 *   POST /Accounts/{sid}/Calls.json  — create an outbound call (agent leg; TwiML then dials the customer)
 * Request signatures: X-Twilio-Signature = base64(HMAC-SHA1(auth_token, url + sorted POST params)).
 */
class TwilioClient
{
    public function __construct(private Integration $integration) {}

    public static function for(Integration $i): self
    {
        return new self($i);
    }

    public function configured(): bool
    {
        return (bool) ($this->integration->cfg('account_sid') && $this->integration->secret('auth_token') && $this->integration->cfg('from_number'));
    }

    private function base(): string
    {
        return rtrim(config('services.twilio.api_url', 'https://api.twilio.com'), '/').'/2010-04-01/Accounts/'.$this->integration->cfg('account_sid');
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth((string) $this->integration->cfg('account_sid'), (string) $this->integration->secret('auth_token'))
            ->acceptJson()->asForm()->timeout(15)->connectTimeout(5);
    }

    /** @return array{friendly_name:string, status:string, type:string} */
    public function verify(): array
    {
        $r = $this->request(fn () => $this->http()->get($this->base().'.json'));

        return ['friendly_name' => (string) $r->json('friendly_name'), 'status' => (string) $r->json('status'), 'type' => (string) $r->json('type')];
    }

    /**
     * Creates the agent leg. Twilio calls $to (the rep), then fetches $twimlUrl which <Dial>s the customer.
     *
     * @return array{sid:string, status:string}
     */
    public function createCall(string $to, string $from, string $twimlUrl, string $statusCallback, int $timeout = 25): array
    {
        $r = $this->request(fn () => $this->http()->post($this->base().'/Calls.json', [
            'To' => $to, 'From' => $from, 'Url' => $twimlUrl, 'Method' => 'POST', 'Timeout' => $timeout,
            'StatusCallback' => $statusCallback, 'StatusCallbackMethod' => 'POST',
            'StatusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
        ]));

        return ['sid' => (string) $r->json('sid'), 'status' => (string) $r->json('status')];
    }

    /** Twilio request signature check (constant time). $url must be exactly what Twilio requested, query string included. */
    public function validSignature(string $url, array $postParams, ?string $signature): bool
    {
        if (! $signature) {
            return false;
        }
        ksort($postParams);
        $data = $url;
        foreach ($postParams as $k => $v) {
            $data .= $k.(is_array($v) ? implode('', $v) : (string) $v);
        }
        $expected = base64_encode(hash_hmac('sha1', $data, (string) $this->integration->secret('auth_token'), true));

        return hash_equals($expected, $signature);
    }

    private function request(callable $fn): Response
    {
        try {
            $r = $fn();
        } catch (ConnectionException $e) {
            $msg = 'Could not reach Twilio: '.mb_substr(trim(preg_replace('~\s*\(see https?://\S+\)|\s+for https?://\S+~', '', $e->getMessage())), 0, 150);
            $this->integration->markError($msg);
            throw new TelephonyException($msg);
        }
        if (! $r->successful()) {
            $msg = $r->json('message') ?: ('HTTP '.$r->status());
            $code = $r->json('code');
            $this->integration->markError(($code ? "Twilio error $code: " : 'Twilio: ').$msg);
            throw new TelephonyException(($code ? "Twilio error $code: " : '').$msg);
        }
        $this->integration->forceFill(['last_success_at' => now()])->saveQuietly();

        return $r;
    }
}
