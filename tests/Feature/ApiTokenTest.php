<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    public function test_tokens_authenticate_without_cookies_and_inherit_owner_permissions(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $rep = $this->makeUser($org, 'sales_rep');
        $this->api($rep, 'POST', 'api-tokens', ['name' => 'zap'])->assertForbidden(); // no api.tokens permission

        $res = $this->api($admin, 'POST', 'api-tokens', ['name' => 'Zapier', 'expires_in_days' => 30])->assertCreated();
        $plain = $res->json('data.token');
        $this->assertStringStartsWith('lf_', $plain);
        $this->assertStringNotContainsString($plain, json_encode($this->api($admin, 'GET', 'api-tokens')->json())); // never shown again

        auth()->guard('web')->forgetUser(); // drop the actingAs session so the bearer token is the only credential
        $this->assertGuest();
        $h = ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];
        // No session, no CSRF token: still works with a bearer token.
        $this->postJson('/api/v1/leads', ['contact' => ['name' => 'Via API', 'phone' => '03001234567'], 'lead_source_id' => $this->lookup($org, \App\Models\LeadSource::class, 'website_form')], $h)->assertCreated();
        $this->getJson('/api/v1/leads', $h)->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotNull(ApiToken::withoutGlobalScopes()->first()->last_used_at);

        // Wrong token → 401; no credential at all → 401.
        $this->getJson('/api/v1/leads', ['Authorization' => 'Bearer lf_nope', 'Accept' => 'application/json'])->assertStatus(401);
        auth()->guard('web')->forgetUser(); // the test guard keeps the last request's user; real requests start fresh
        $this->postJson('/api/v1/leads', ['contact' => ['phone' => '03001234567']], ['Accept' => 'application/json'])->assertStatus(401);
        // A token cannot manage tokens.
        $this->postJson('/api/v1/api-tokens', ['name' => 'x'], $h)->assertForbidden();
        $this->api($admin, 'DELETE', 'api-tokens/'.$res->json('data.record.id'))->assertOk();
        auth()->guard('web')->forgetUser();
        $this->getJson('/api/v1/leads', $h)->assertStatus(401);

        // Tenant isolation: another org's admin cannot see the token-created lead.
        $other = $this->makeOrg('Other');
        $this->api($this->makeUser($other), 'GET', 'leads')->assertOk()->assertJsonCount(0, 'data');
    }
}
