<?php

namespace App\Services\Ai;

use App\Models\AiAssistant;
use App\Models\Integration;

/** Resolves configured AI clients for the current organization. */
class ProviderRegistry
{
    public function integration(string $provider): ?Integration
    {
        return Integration::where('provider', $provider)->first();
    }

    public function client(string $provider): ?ChatClient
    {
        $cfg = config("ai.providers.$provider");
        $integration = $this->integration($provider);
        if (! $cfg || ! $integration || $integration->status === 'disabled' || ! $integration->secret('api_key')) {
            return null;
        }

        return new ChatClient($provider, rtrim($integration->cfg('base_url') ?: $cfg['base_url'], '/'), $integration->secret('api_key'), $cfg['max_tokens_param']);
    }

    public function forAssistant(AiAssistant $assistant): ?ChatClient
    {
        return $this->client($assistant->provider);
    }

    public function modelFor(AiAssistant $assistant): ?string
    {
        return $assistant->model ?: ($this->integration($assistant->provider)?->cfg('default_model') ?: config("ai.providers.{$assistant->provider}.default_model"));
    }

    /** Embeddings come from OpenAI when connected (DeepSeek has none). */
    public function embedder(): ?array
    {
        $client = $this->client('openai');
        if (! $client) {
            return null;
        }
        $model = $this->integration('openai')->cfg('embedding_model') ?: config('ai.providers.openai.default_embedding_model');

        return [$client, $model];
    }

    /** Record success/failure on the integration so the UI shows an honest status. */
    public function track(string $provider, ?\Throwable $e = null): void
    {
        $i = $this->integration($provider);
        if (! $i) {
            return;
        }
        $e ? $i->markError($e->getMessage()) : $i->markSuccess();
    }
}
