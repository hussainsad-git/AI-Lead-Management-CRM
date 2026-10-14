<?php

namespace App\Services\Meta;

use App\Models\Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Meta Graph API (Marketing API + Lead Ads). Documented endpoints only:
 *  GET /me, GET /act_{id}, GET /act_{id}/{campaigns|adsets|ads|insights}, GET /{page_id}/leadgen_forms,
 *  GET /{form_id}/leads, GET /{leadgen_id}, POST /{page_id}/subscribed_apps.
 * The system-user token reads ads; a Page token (or the same token with Page access) reads leads.
 */
class MetaAdsClient
{
    private const PAGE_LIMIT = 50; // pages of results per listing call (safety stop)

    public function __construct(private Integration $integration) {}

    public static function for(Integration $i): self
    {
        return new self($i);
    }

    public function configured(): bool
    {
        return (bool) ($this->integration->secret('access_token') && $this->accountId());
    }

    public function accountId(): ?string
    {
        $id = preg_replace('/\D/', '', (string) $this->integration->cfg('ad_account_id'));

        return $id ? 'act_'.$id : null;
    }

    private function base(): string
    {
        return rtrim(config('ai.whatsapp.graph_url'), '/').'/'.($this->integration->cfg('api_version') ?: config('ai.whatsapp.default_api_version'));
    }

    private function http(?string $token = null): PendingRequest
    {
        return Http::withToken($token ?? (string) $this->integration->secret('access_token'))->acceptJson()->timeout(30)
            ->retry(3, 1000, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response->status(), [500, 502, 503], true)), throw: false);
    }

    private function pageToken(): string
    {
        return (string) ($this->integration->secret('page_access_token') ?: $this->integration->secret('access_token'));
    }

    // ---------------- verification ----------------

    /** @return array{user: ?string, account_name: ?string, currency: ?string, timezone: ?string, account_status: ?int} */
    public function verify(): array
    {
        $me = $this->get('/me', ['fields' => 'id,name']);
        $acct = $this->get('/'.$this->accountId(), ['fields' => 'name,currency,timezone_name,account_status']);

        return ['user' => $me->json('name'), 'account_name' => $acct->json('name'), 'currency' => $acct->json('currency'),
            'timezone' => $acct->json('timezone_name'), 'account_status' => $acct->json('account_status')];
    }

    /** Page name if the page token can read it (Lead Ads prerequisite). */
    public function verifyPage(): ?string
    {
        $page = $this->integration->cfg('page_id');

        return $page ? $this->get('/'.$page, ['fields' => 'id,name'], $this->pageToken())->json('name') : null;
    }

    // ---------------- structure & insights ----------------

    public function campaigns(): array
    {
        return $this->all('/'.$this->accountId().'/campaigns', ['fields' => 'id,name,objective,status,effective_status,start_time,stop_time', 'limit' => 200]);
    }

    public function adSets(): array
    {
        return $this->all('/'.$this->accountId().'/adsets', ['fields' => 'id,name,campaign_id,status,effective_status', 'limit' => 200]);
    }

    public function ads(): array
    {
        return $this->all('/'.$this->accountId().'/ads', ['fields' => 'id,name,adset_id,campaign_id,status,effective_status', 'limit' => 200]);
    }

    /** Daily campaign-level spend and actions. Dates are in the ad account's time zone. */
    public function dailyCampaignInsights(string $since, string $until): array
    {
        return $this->all('/'.$this->accountId().'/insights', [
            'level' => 'campaign', 'time_increment' => 1,
            'time_range' => json_encode(['since' => $since, 'until' => $until]),
            'fields' => 'campaign_id,campaign_name,spend,actions,date_start,date_stop,account_currency',
            'limit' => 500,
        ]);
    }

    // ---------------- lead ads ----------------

    public function leadForms(): array
    {
        $page = $this->integration->cfg('page_id') ?: throw new MetaException('Set the Facebook Page ID to use Lead Ads.');

        return $this->all('/'.$page.'/leadgen_forms', ['fields' => 'id,name,status,leads_count', 'limit' => 100], $this->pageToken());
    }

    public function lead(string $leadgenId): array
    {
        return $this->get('/'.$leadgenId, ['fields' => 'id,created_time,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,field_data,is_organic,platform,custom_disclaimer_responses'], $this->pageToken())->json();
    }

    /** Leads of one form created at/after a unix timestamp. */
    public function formLeads(string $formId, int $since): array
    {
        return $this->all('/'.$formId.'/leads', [
            'fields' => 'id,created_time,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,field_data,is_organic,platform,custom_disclaimer_responses',
            'filtering' => json_encode([['field' => 'time_created', 'operator' => 'GREATER_THAN_OR_EQUAL', 'value' => $since]]),
            'limit' => 100,
        ], $this->pageToken());
    }

    /** Subscribes the app to the Page's leadgen webhook field (needs pages_manage_metadata). */
    public function subscribePage(): bool
    {
        $page = $this->integration->cfg('page_id') ?: throw new MetaException('Set the Facebook Page ID first.');

        return (bool) $this->request('post', '/'.$page.'/subscribed_apps', ['subscribed_fields' => 'leadgen'], $this->pageToken())->json('success');
    }

    // ---------------- custom audiences (needs ads_management + accepted Custom Audience terms) ----------------

    /** @return string the new audience id */
    public function createCustomAudience(string $name, ?string $description): string
    {
        $r = $this->request('post', '/'.$this->accountId().'/customaudiences', [
            'name' => mb_substr($name, 0, 191), 'description' => mb_substr((string) $description, 0, 500),
            'subtype' => 'CUSTOM', 'customer_file_source' => 'USER_PROVIDED_ONLY',
        ]);

        return (string) ($r->json('id') ?: throw new MetaException('Meta did not return an audience id.'));
    }

    /** @return array{name:?string, approximate_count_lower_bound:?int, approximate_count_upper_bound:?int, delivery_status:?array, operation_status:?array, time_updated:?string} */
    public function customAudience(string $id): array
    {
        return $this->get('/'.$id, ['fields' => 'name,approximate_count_lower_bound,approximate_count_upper_bound,delivery_status,operation_status,time_updated'])->json();
    }

    /**
     * Replaces the audience membership with the batches of one session (documented "usersreplace" flow).
     * $rows are already SHA-256 hashed, column order = $schema.
     */
    public function replaceUsersBatch(string $audienceId, string $sessionId, int $batchSeq, bool $last, int $estimatedTotal, array $schema, array $rows): array
    {
        $r = $this->request('post', '/'.$audienceId.'/usersreplace', [
            'session' => json_encode(['session_id' => (int) $sessionId, 'batch_seq' => $batchSeq, 'last_batch_flag' => $last, 'estimated_num_total' => $estimatedTotal]),
            'payload' => json_encode(['schema' => $schema, 'data' => $rows]),
        ]);

        return (array) $r->json();
    }

    // ---------------- transport ----------------

    private function all(string $path, array $params, ?string $token = null): array
    {
        $out = [];
        for ($i = 0; $i < self::PAGE_LIMIT; $i++) {
            $r = $this->get($path, $params, $token);
            array_push($out, ...(array) $r->json('data'));
            $after = $r->json('paging.cursors.after');
            if (! $r->json('paging.next') || ! $after) {
                break;
            }
            $params['after'] = $after;
        }

        return $out;
    }

    private function get(string $path, array $params = [], ?string $token = null): Response
    {
        return $this->request('get', $path, $params, $token);
    }

    private function request(string $method, string $path, array $data, ?string $token = null): Response
    {
        try {
            $http = $this->http($token);
            $r = $method === 'get' ? $http->get($this->base().$path, $data) : $http->asForm()->post($this->base().$path, $data);
        } catch (ConnectionException $e) {
            throw new MetaException('Could not reach the Meta Graph API: '.$e->getMessage());
        }
        if (! $r->successful()) {
            $code = $r->json('error.code');
            $msg = (string) ($r->json('error.error_user_msg') ?: $r->json('error.message') ?: 'HTTP '.$r->status());
            $prefix = match (true) {
                $code === 190 => 'Access token invalid or expired: ',
                $code === 10 || ($code >= 200 && $code < 300) => 'Missing permission: ',
                default => '',
            };
            throw new MetaException($prefix.mb_substr($msg, 0, 300), is_numeric($code) ? (int) $code : null, $r->status());
        }

        return $r;
    }
}
