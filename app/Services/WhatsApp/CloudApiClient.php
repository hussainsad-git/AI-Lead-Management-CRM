<?php

namespace App\Services\WhatsApp;

use App\Models\Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Official WhatsApp Business Platform (Cloud API) client. Uses only documented Graph API endpoints. */
class CloudApiClient
{
    public function __construct(private Integration $integration) {}

    public static function for(Integration $i): self
    {
        return new self($i);
    }

    public function configured(): bool
    {
        return (bool) ($this->integration->secret('access_token') && $this->integration->cfg('phone_number_id'));
    }

    private function base(): string
    {
        return rtrim(config('ai.whatsapp.graph_url'), '/').'/'.($this->integration->cfg('api_version') ?: config('ai.whatsapp.default_api_version'));
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) $this->integration->secret('access_token'))->acceptJson()->asJson()->timeout(20)
            ->retry(2, 700, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof \Illuminate\Http\Client\RequestException && in_array($e->response->status(), [429, 500, 503], true)), throw: false);
    }

    /** @return array{display_phone_number: ?string, verified_name: ?string, quality_rating: ?string} */
    public function verify(): array
    {
        $r = $this->call('get', '/'.$this->integration->cfg('phone_number_id'), ['fields' => 'display_phone_number,verified_name,quality_rating']);

        return ['display_phone_number' => $r->json('display_phone_number'), 'verified_name' => $r->json('verified_name'), 'quality_rating' => $r->json('quality_rating')];
    }

    public function sendText(string $toWaId, string $body): string
    {
        return $this->send($toWaId, ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $body]]);
    }

    public function sendTemplate(string $toWaId, string $name, string $language, array $bodyParams = []): string
    {
        $tpl = ['name' => $name, 'language' => ['code' => $language]];
        if ($bodyParams) {
            $tpl['components'] = [['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $bodyParams)]];
        }

        return $this->send($toWaId, ['type' => 'template', 'template' => $tpl]);
    }

    public function markRead(string $wamid): void
    {
        $this->call('post', '/'.$this->integration->cfg('phone_number_id').'/messages', ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $wamid]);
    }

    /** @return array<int, array> all message templates of the WhatsApp Business Account */
    public function templates(): array
    {
        $out = [];
        $url = '/'.$this->integration->cfg('waba_id').'/message_templates';
        $params = ['limit' => 100, 'fields' => 'id,name,language,status,category,components'];
        for ($page = 0; $page < 20 && $url; $page++) {
            $r = $this->call('get', $url, $params);
            array_push($out, ...(array) $r->json('data'));
            $after = $r->json('paging.cursors.after');
            $url = $r->json('paging.next') && $after ? $url : null;
            $params['after'] = $after;
        }

        return $out;
    }

    private function send(string $to, array $message): string
    {
        $r = $this->call('post', '/'.$this->integration->cfg('phone_number_id').'/messages',
            ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => ltrim($to, '+')] + $message);
        $id = $r->json('messages.0.id');
        if (! $id) {
            throw new WhatsAppException('WhatsApp did not return a message id.');
        }

        return $id;
    }

    private function call(string $method, string $path, array $data = []): Response
    {
        try {
            $r = $this->http()->{$method}($this->base().$path, $data);
        } catch (ConnectionException $e) {
            throw new WhatsAppException('Could not reach WhatsApp Cloud API: '.$e->getMessage());
        }
        if (! $r->successful()) {
            $code = $r->json('error.code');
            $msg = (string) ($r->json('error.error_data.details') ?: $r->json('error.message') ?: 'HTTP '.$r->status());
            throw new WhatsAppException(($code === 190 ? 'Access token invalid or expired: ' : '').mb_substr($msg, 0, 300), is_numeric($code) ? (int) $code : null, $r->status());
        }

        return $r;
    }
}
