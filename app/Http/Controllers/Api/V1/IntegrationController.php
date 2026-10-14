<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRuleException;
use App\Models\Integration;
use App\Models\MessageTemplate;
use App\Models\WebhookEvent;
use App\Services\Ai\AiException;
use App\Services\Ai\ProviderRegistry;
use App\Services\AuditLogger;
use App\Services\Meta\LeadAdsService;
use App\Services\Meta\MetaAdsClient;
use App\Services\Meta\MetaException;
use App\Services\Meta\MetaSyncService;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\MessagingService;
use App\Services\WhatsApp\WhatsAppException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Connection setup and honest status. Secrets are write-only: responses expose only "set" flags
 * and a last-4 hint, never the values.
 */
class IntegrationController extends ApiController
{
    private const PROVIDERS = [
        'whatsapp_cloud' => ['secrets' => ['access_token', 'app_secret', 'verify_token'], 'config' => ['waba_id', 'phone_number_id', 'api_version', 'business_id']],
        'openai' => ['secrets' => ['api_key'], 'config' => ['default_model', 'embedding_model', 'base_url']],
        'deepseek' => ['secrets' => ['api_key'], 'config' => ['default_model', 'base_url']],
        'meta_marketing' => ['secrets' => ['access_token', 'app_secret', 'verify_token', 'page_access_token'], 'config' => ['ad_account_id', 'page_id', 'api_version', 'initial_days', 'lead_ads_enabled']],
        'twilio_voice' => ['secrets' => ['auth_token'], 'config' => ['account_sid', 'from_number', 'record_calls', 'agent_prompt']],
    ];

    private function guard(): void
    {
        abort_unless($this->user()->hasPermission('integrations.manage'), 403);
    }

    private function find(string $provider, bool $create = false): ?Integration
    {
        abort_unless(isset(self::PROVIDERS[$provider]), 404);
        $i = Integration::where('provider', $provider)->first();
        if (! $i && $create) {
            $i = Integration::create(['provider' => $provider, 'status' => 'not_configured', 'config' => [],
                'webhook_key' => in_array($provider, ['whatsapp_cloud', 'meta_marketing', 'twilio_voice'], true) ? Str::random(40) : null]);
        }

        return $i;
    }

    public function status()
    {
        $this->guard();

        return $this->ok(collect(array_keys(self::PROVIDERS))->map(fn ($p) => $this->present($p, $this->find($p)))->values()->all());
    }

    public function update(Request $request, string $provider, AuditLogger $audit)
    {
        $this->guard();
        $spec = self::PROVIDERS[$provider] ?? abort(404);
        $rules = [];
        foreach ($spec['secrets'] as $s) {
            $rules["secrets.$s"] = ['nullable', 'string', 'max:2048'];
        }
        foreach ($spec['config'] as $c) {
            $rules["config.$c"] = ['nullable', 'string', 'max:255'];
        }
        $rules['config.base_url'] = ['nullable', 'url:https', 'max:255'];
        $rules['config.api_version'] = ['nullable', 'regex:/^v\d{2}\.\d$/'];
        $rules['config.phone_number_id'] = ['nullable', 'regex:/^\d{5,25}$/'];
        $rules['config.waba_id'] = ['nullable', 'regex:/^\d{5,25}$/'];
        $rules['config.ad_account_id'] = ['nullable', 'regex:/^(act_)?\d{5,25}$/'];
        $rules['config.page_id'] = ['nullable', 'regex:/^\d{5,25}$/'];
        $rules['config.initial_days'] = ['nullable', 'integer', 'min:1', 'max:90'];
        $rules['config.lead_ads_enabled'] = ['nullable', 'boolean'];
        $rules['config.account_sid'] = ['nullable', 'regex:/^AC[0-9a-fA-F]{32}$/'];
        $rules['config.from_number'] = ['nullable', 'regex:/^\+[1-9]\d{6,14}$/'];
        $rules['config.record_calls'] = ['nullable', 'boolean'];
        $rules['config.agent_prompt'] = ['nullable', 'string', 'max:120'];
        $rules['enabled'] = ['sometimes', 'boolean'];
        $data = $request->validate($rules, [], ['config.ad_account_id' => 'ad account ID', 'config.page_id' => 'Page ID', 'config.phone_number_id' => 'phone number ID',
            'config.waba_id' => 'WhatsApp Business Account ID', 'config.api_version' => 'API version', 'config.initial_days' => 'days of spend',
            'config.account_sid' => 'Account SID (starts with AC)', 'config.from_number' => 'caller ID number (E.164, e.g. +1415…)']);

        $i = $this->find($provider, true);
        $config = array_merge($i->config ?? [], array_filter($data['config'] ?? [], fn ($v) => $v !== null));
        foreach ($data['config'] ?? [] as $k => $v) {
            if ($v === '') {
                unset($config[$k]);
            }
        }
        $creds = $i->credentials ?? [];
        $changedSecrets = [];
        foreach ($data['secrets'] ?? [] as $k => $v) {
            if ($v !== null && $v !== '') { // blank = keep existing (write-only fields)
                $creds[$k] = trim($v);
                $changedSecrets[] = $k;
            }
        }
        if (isset($config['lead_ads_enabled'])) {
            $config['lead_ads_enabled'] = filter_var($config['lead_ads_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($config['ad_account_id'])) {
            $config['ad_account_id'] = preg_replace('/\D/', '', (string) $config['ad_account_id']);
        }
        if (in_array($provider, ['whatsapp_cloud', 'meta_marketing'], true) && empty($creds['verify_token'])) {
            $creds['verify_token'] = Str::random(32);
        }
        $i->config = $config;
        $i->credentials = $creds;
        if (array_key_exists('enabled', $data)) {
            $i->status = $data['enabled'] ? ($i->status === 'disabled' ? 'not_configured' : $i->status) : 'disabled';
        }
        if ($changedSecrets && $i->status !== 'disabled') {
            $i->status = 'not_configured'; // must be re-tested
            $i->verified_at = null;
        }
        $i->save();
        $audit->log('integration.updated', $i, [], ['provider' => $provider, 'config_keys' => array_keys($config), 'secrets_changed' => $changedSecrets]);

        return $this->ok($this->present($provider, $i->fresh()));
    }

    public function test(string $provider, ProviderRegistry $providers)
    {
        $this->guard();
        $i = $this->find($provider) ?? throw new DomainRuleException('Save the credentials first.', 'not_configured');
        $detail = null;
        try {
            if ($provider === 'whatsapp_cloud') {
                if (! CloudApiClient::for($i)->configured()) {
                    throw new DomainRuleException('Phone number ID and access token are required.', 'not_configured');
                }
                $detail = CloudApiClient::for($i)->verify();
                $i->config = array_merge($i->config ?? [], ['display_phone_number' => $detail['display_phone_number'], 'verified_name' => $detail['verified_name']]);
            } elseif ($provider === 'meta_marketing') {
                $meta = MetaAdsClient::for($i);
                if (! $meta->configured()) {
                    throw new DomainRuleException('Ad account ID and access token are required.', 'not_configured');
                }
                $detail = $meta->verify();
                $detail['page_name'] = $meta->verifyPage();
                $i->config = array_merge($i->config ?? [], ['account_name' => $detail['account_name'], 'account_currency' => $detail['currency'],
                    'account_timezone' => $detail['timezone'], 'page_name' => $detail['page_name']]);
            } elseif ($provider === 'twilio_voice') {
                $tw = \App\Services\Telephony\TwilioClient::for($i);
                if (! $tw->configured()) {
                    throw new DomainRuleException('Account SID, auth token and a caller ID number are required.', 'not_configured');
                }
                $detail = $tw->verify();
                if ($detail['status'] !== 'active') {
                    throw new \App\Services\Telephony\TelephonyException('Twilio account status is "'.$detail['status'].'"');
                }
                $i->config = array_merge($i->config ?? [], ['account_name' => $detail['friendly_name'], 'account_type' => $detail['type']]);
            } else {
                $client = $providers->client($provider) ?? throw new DomainRuleException('API key is required.', 'not_configured');
                $models = $client->models();
                $detail = ['models_available' => count($models)];
            }
            $i->forceFill(['status' => 'connected', 'verified_at' => now(), 'last_success_at' => now(), 'last_error' => null])->save();
        } catch (WhatsAppException|AiException|MetaException|\App\Services\Telephony\TelephonyException $e) {
            $i->markError($e->getMessage());
            throw new DomainRuleException('Connection test failed: '.$e->getMessage(), 'connection_failed', 422);
        }

        return $this->ok(['status' => 'connected', 'detail' => $detail] + ['integration' => $this->present($provider, $i->fresh())]);
    }

    public function rotateWebhook(string $provider, AuditLogger $audit)
    {
        $this->guard();
        abort_unless(in_array($provider, ['whatsapp_cloud', 'meta_marketing'], true), 404);
        $i = $this->find($provider, true);
        $creds = $i->credentials ?? [];
        $creds['verify_token'] = Str::random(32);
        $i->forceFill(['webhook_key' => Str::random(40), 'credentials' => $creds])->save();
        $audit->log('integration.webhook_rotated', $i);

        return $this->ok($this->present($provider, $i->fresh()));
    }

    /** One-time reveal of the verify token so it can be pasted into Meta's webhook settings. */
    public function revealVerifyToken(AuditLogger $audit, string $provider = 'whatsapp_cloud')
    {
        $this->guard();
        abort_unless(in_array($provider, ['whatsapp_cloud', 'meta_marketing'], true), 404);
        $i = $this->find($provider) ?? abort(404);
        $audit->log('integration.verify_token_viewed', $i);

        return $this->ok(['verify_token' => $i->secret('verify_token')]);
    }

    /** Manual "Sync now": campaigns, spend and (if enabled) Instant Form leads. Runs inline so the admin sees the result. */
    public function metaSync(Request $request, MetaSyncService $sync, LeadAdsService $leadAds)
    {
        $this->guard();
        $i = $this->find('meta_marketing') ?? throw new DomainRuleException('Save the Meta credentials first.', 'not_configured');
        $days = $request->integer('days') ?: null;
        try {
            $s = $sync->sync($i, $days ? min(90, max(1, $days)) : null);
            if ($i->cfg('page_id') && $i->cfg('lead_ads_enabled')) {
                $s['lead_ads'] = $leadAds->backfill($i, $days ? now()->subDays($days)->timestamp : now()->subDays(7)->timestamp);
            }
        } catch (MetaException $e) {
            $i->markError($e->getMessage());
            throw new DomainRuleException('Meta sync failed: '.$e->getMessage(), 'sync_failed', 422);
        }

        return $this->ok($s + ['integration' => $this->present('meta_marketing', $i->fresh())]);
    }

    public function metaSubscribePage(AuditLogger $audit)
    {
        $this->guard();
        $i = $this->find('meta_marketing') ?? throw new DomainRuleException('Save the Meta credentials first.', 'not_configured');
        try {
            $ok = MetaAdsClient::for($i)->subscribePage();
        } catch (MetaException $e) {
            throw new DomainRuleException('Could not subscribe the Page: '.$e->getMessage(), 'subscribe_failed', 422);
        }
        $i->config = array_merge($i->config ?? [], ['page_subscribed_at' => now()->toIso8601ZuluString()]);
        $i->save();
        $audit->log('integration.page_subscribed', $i);

        return $this->ok(['subscribed' => $ok, 'integration' => $this->present('meta_marketing', $i->fresh())]);
    }

    public function syncTemplates(MessagingService $messaging)
    {
        $this->guard();

        return $this->ok(['synced' => $messaging->syncTemplates(), 'templates' => MessageTemplate::orderBy('name')->get(['id', 'name', 'language', 'category', 'status', 'body_params', 'synced_at'])]);
    }

    public function templates()
    {
        abort_unless($this->user()->hasAnyPermission('integrations.manage', 'whatsapp.send'), 403);

        return $this->ok(MessageTemplate::orderBy('name')->get(['id', 'name', 'language', 'category', 'status', 'body_params', 'synced_at']));
    }

    private function present(string $provider, ?Integration $i): array
    {
        $spec = self::PROVIDERS[$provider];
        $names = ['whatsapp_cloud' => 'WhatsApp Business Platform (Cloud API)', 'openai' => 'OpenAI', 'deepseek' => 'DeepSeek', 'meta_marketing' => 'Meta Marketing API & Lead Ads', 'twilio_voice' => 'Twilio Voice (click-to-call)'];
        $out = [
            'key' => $provider, 'name' => $names[$provider], 'status' => $i?->status ?? 'not_configured',
            'config' => collect($spec['config'])->mapWithKeys(fn ($k) => [$k => $i?->cfg($k)])->all()
                + ($provider === 'whatsapp_cloud' ? ['display_phone_number' => $i?->cfg('display_phone_number'), 'verified_name' => $i?->cfg('verified_name')] : []),
            'secrets' => collect($spec['secrets'])->mapWithKeys(fn ($k) => [$k => ['set' => (bool) $i?->secret($k), 'hint' => $k === 'verify_token' ? null : $i?->secretHint($k)]])->all(),
            'verified_at' => $i?->verified_at?->toIso8601ZuluString(), 'last_success_at' => $i?->last_success_at?->toIso8601ZuluString(),
            'last_error' => $i?->last_error, 'last_error_at' => $i?->last_error_at?->toIso8601ZuluString(),
        ];
        if ($provider === 'whatsapp_cloud') {
            $out['webhook_url'] = $i?->webhook_key ? url('/webhooks/whatsapp/'.$i->webhook_key) : null;
            $out['last_webhook_at'] = $i?->last_webhook_at?->toIso8601ZuluString();
            $out['webhook_failures_24h'] = $i ? WebhookEvent::where('integration_id', $i->id)->where('status', 'failed')->where('created_at', '>', now()->subDay())->count() : 0;
            $out['templates'] = MessageTemplate::count();
            $out['requirements'] = ['Meta Business portfolio and WhatsApp Business Account', 'Phone number registered on the Cloud API',
                'Meta app permissions: whatsapp_business_messaging, whatsapp_business_management', 'Permanent system-user access token',
                'App secret (to verify webhook signatures)', 'Subscribe the app to the "messages" webhook field using the URL and verify token below'];
        } elseif ($provider === 'meta_marketing') {
            $out['config'] += ['account_name' => $i?->cfg('account_name'), 'account_currency' => $i?->cfg('account_currency'), 'account_timezone' => $i?->cfg('account_timezone'),
                'page_name' => $i?->cfg('page_name'), 'last_spend_sync_at' => $i?->cfg('last_spend_sync_at'), 'last_sync_range' => $i?->cfg('last_sync_range'),
                'last_backfill_at' => $i?->cfg('last_backfill_at'), 'page_subscribed_at' => $i?->cfg('page_subscribed_at')];
            $out['webhook_url'] = $i?->webhook_key ? url('/webhooks/meta/'.$i->webhook_key) : null;
            $out['last_webhook_at'] = $i?->last_webhook_at?->toIso8601ZuluString();
            $out['webhook_failures_24h'] = $i ? WebhookEvent::where('integration_id', $i->id)->where('status', 'failed')->where('created_at', '>', now()->subDay())->count() : 0;
            $out['campaigns_synced'] = \App\Models\Campaign::where('data_source', 'meta_api')->count();
            $out['forms'] = \App\Models\LeadForm::count();
            $out['submissions'] = \App\Models\LeadFormSubmission::selectRaw('outcome, COUNT(*) as c')->groupBy('outcome')->pluck('c', 'outcome');
            $out['currency_mismatch'] = $i?->cfg('account_currency') && $i->cfg('account_currency') !== app(\App\Support\Tenancy\CurrentOrganization::class)->require()->currency;
            $out['requirements'] = ['Meta app (Business type) with Marketing API access; a System User in Business Manager with the ad account assigned',
                'System-user token with ads_read (campaigns, spend). Spend is reported in the ad account currency and only counts in CRM metrics when it matches the organization currency',
                'Lead Ads: leads_retrieval, pages_show_list, pages_read_engagement, pages_manage_ads, pages_manage_metadata; the Page assigned to the system user (or a Page access token)',
                'App secret (webhook signatures) and the webhook below subscribed to the Page "leadgen" field (Meta App → Webhooks → Page)',
                'Advanced Access for leads_retrieval is required to receive leads from forms you do not own; standard access covers your own Pages'];
        } elseif ($provider === 'twilio_voice') {
            $out['config'] += ['account_name' => $i?->cfg('account_name'), 'account_type' => $i?->cfg('account_type')];
            $out['last_webhook_at'] = $i?->last_webhook_at?->toIso8601ZuluString();
            $out['calls_30d'] = $i ? \App\Models\TelephonyCall::where('created_at', '>', now()->subDays(30))->count() : 0;
            $out['calls_failed_7d'] = $i ? \App\Models\TelephonyCall::where('status', 'failed')->where('created_at', '>', now()->subDays(7))->count() : 0;
            $out['requirements'] = ['Twilio account (upgraded, not trial, to call unverified numbers) with a voice-capable number as caller ID; check Twilio\'s Geo Permissions for the countries you call',
                'Account SID and Auth Token from the Twilio Console (a main-account token is required for request-signature validation)',
                'APP_URL must be a public HTTPS URL: Twilio fetches TwiML and posts call status to /webhooks/telephony/… (no manual webhook setup — URLs are sent per call)',
                'Each salesperson needs their own phone number on their profile; the provider rings them first and then bridges the customer',
                'Call recording is off by default; enabling it is your responsibility under local consent laws, and recordings stay on Twilio (only the link is stored)'];
        } else {
            $out['requirements'] = $provider === 'openai'
                ? ['API key from platform.openai.com (project key recommended)', 'Use "Load models" on the AI Assistant page to pick a chat model', 'Embeddings (semantic search) use text-embedding-3-small by default']
                : ['API key from platform.deepseek.com', 'Default model deepseek-v4-flash (deepseek-chat was retired 24 Jul 2026)', 'DeepSeek has no embeddings API: the knowledge base uses keyword search unless OpenAI is also connected'];
        }

        return $out;
    }
}
