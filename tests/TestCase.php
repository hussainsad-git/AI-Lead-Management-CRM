<?php

namespace Tests;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Shared stores (Redis) keep rate-limiter and heartbeat state between tests; the default array store resets itself.
        // Unique-job locks live on the lock connection (a different Redis DB than the cache), so flush that too.
        if (config('cache.default') === 'redis') {
            \Illuminate\Support\Facades\Cache::flush();
            \Illuminate\Support\Facades\Redis::connection(config('cache.stores.redis.lock_connection', 'default'))->flushdb();
        } elseif (config('cache.default') !== 'array') {
            \Illuminate\Support\Facades\Cache::flush();
        }
    }

    protected function makeOrg(string $name = 'Acme Realty'): Organization
    {
        return app(OrganizationProvisioner::class)->create($name);
    }

    protected function makeUser(Organization $org, string $role = 'business_admin', array $attrs = []): User
    {
        return $this->inOrg($org, function () use ($org, $role, $attrs) {
            $u = new User(array_merge(['name' => ucfirst($role).' '.uniqid(), 'email' => uniqid($role).'@test.local', 'password' => 'Password123!'], $attrs));
            $u->organization_id = $org->id;
            $u->save();
            $u->roles()->sync([Role::where('key', $role)->value('id')]);

            return $u->fresh();
        });
    }

    protected function inOrg(Organization $org, callable $fn): mixed
    {
        return app(CurrentOrganization::class)->runAs($org, $fn);
    }

    /** Session-authenticated JSON request the way the UI makes it. */
    protected function api(User $user, string $method, string $uri, array $data = [])
    {
        return $this->actingAs($user)->withHeader('Accept', 'application/json')->json($method, '/api/v1/'.ltrim($uri, '/'), $data);
    }

    protected function lookup(Organization $org, string $class, string $key): int
    {
        return $this->inOrg($org, fn () => $class::where('key', $key)->value('id'));
    }

    protected function createLead(Organization $org, User $user, array $overrides = []): array
    {
        $payload = array_replace_recursive([
            'contact' => ['name' => 'Test Person', 'phone' => '0300'.random_int(1000000, 9999999)],
            'lead_source_id' => $this->lookup($org, \App\Models\LeadSource::class, 'whatsapp'),
        ], $overrides);
        $res = $this->api($user, 'POST', 'leads', $payload);
        $res->assertCreated();

        return $res->json('data');
    }
}
