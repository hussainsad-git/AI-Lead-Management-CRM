<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client for OpenAI-compatible Chat Completions APIs (OpenAI, DeepSeek).
 * API keys stay server-side; nothing here is ever returned to the browser.
 */
class ChatClient
{
    public function __construct(
        public readonly string $provider,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $maxTokensParam = 'max_tokens',
    ) {}

    private function http(): PendingRequest
    {
        return Http::withToken($this->apiKey)->acceptJson()->asJson()
            ->timeout(config('ai.timeout'))
            ->retry(2, 800, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof \Illuminate\Http\Client\RequestException && in_array($e->response->status(), [429, 500, 502, 503], true)), throw: false);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, prompt_tokens: ?int, completion_tokens: ?int, latency_ms: int, model: string}
     */
    public function chat(array $messages, string $model, float $temperature = 0.3, int $maxTokens = 400, bool $json = false): array
    {
        $payload = ['model' => $model, 'messages' => $messages, $this->maxTokensParam => $maxTokens, 'temperature' => $temperature];
        if ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        $start = microtime(true);
        $res = $this->post('/chat/completions', $payload);
        // Some reasoning models accept only the default temperature; retry once without it.
        if ($res->status() === 400 && str_contains(strtolower((string) $res->json('error.message')), 'temperature')) {
            unset($payload['temperature']);
            $res = $this->post('/chat/completions', $payload);
        }
        $this->guard($res);
        $content = $res->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new AiException('The AI provider returned an empty response.', $res->status());
        }

        return [
            'content' => $content,
            'prompt_tokens' => $res->json('usage.prompt_tokens'),
            'completion_tokens' => $res->json('usage.completion_tokens'),
            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            'model' => (string) ($res->json('model') ?? $model),
        ];
    }

    /** @return array<int, array<float>> */
    public function embed(array $texts, string $model): array
    {
        $res = $this->post('/embeddings', ['model' => $model, 'input' => array_values($texts)]);
        $this->guard($res);
        $out = [];
        foreach ((array) $res->json('data') as $row) {
            $out[(int) $row['index']] = array_map('floatval', $row['embedding']);
        }
        ksort($out);

        return array_values($out);
    }

    /** @return array<string> model ids available to this key */
    public function models(): array
    {
        try {
            $res = $this->http()->get($this->baseUrl.'/models');
        } catch (ConnectionException $e) {
            throw new AiException('Could not reach '.$this->provider.': '.$e->getMessage());
        }
        $this->guard($res);

        return collect((array) $res->json('data'))->pluck('id')->filter()->sort()->values()->all();
    }

    private function post(string $path, array $payload): Response
    {
        try {
            return $this->http()->post($this->baseUrl.$path, $payload);
        } catch (ConnectionException $e) {
            throw new AiException('Could not reach '.$this->provider.': '.$e->getMessage());
        }
    }

    private function guard(Response $res): void
    {
        if ($res->successful()) {
            return;
        }
        $msg = (string) ($res->json('error.message') ?? $res->body());
        $auth = in_array($res->status(), [401, 403], true);
        throw new AiException(($auth ? 'Authentication failed: ' : ucfirst($this->provider).' error: ').mb_substr($msg, 0, 300), $res->status(), $auth);
    }
}
