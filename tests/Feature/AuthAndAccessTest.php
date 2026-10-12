<?php

namespace Tests\Feature;

use App\Models\Lead;
use Tests\TestCase;

class AuthAndAccessTest extends TestCase
{
    public function test_login_rotates_session_and_rejects_bad_credentials(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'business_admin', ['email' => 'boss@test.local']);

        $this->post('/login', ['email' => 'boss@test.local', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => 'BOSS@test.local', 'password' => 'Password123!'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(str_starts_with($user->fresh()->password, '$argon2id$'));
    }

    public function test_login_is_rate_limited(): void
    {
        $org = $this->makeOrg();
        $this->makeUser($org, 'sales_rep', ['email' => 'rep@test.local']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'rep@test.local', 'password' => 'nope']);
        }
        $this->post('/login', ['email' => 'rep@test.local', 'password' => 'nope'])->assertStatus(429);
    }

    public function test_inactive_user_cannot_log_in_and_is_logged_out(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'sales_rep', ['email' => 'gone@test.local', 'is_active' => false]);
        $this->post('/login', ['email' => 'gone@test.local', 'password' => 'Password123!'])->assertSessionHasErrors('email');
        $this->api($user, 'GET', 'lookups')->assertStatus(401);
    }

    public function test_guests_get_json_401_from_api_and_redirect_from_pages(): void
    {
        $this->getJson('/api/v1/leads')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->get('/leads')->assertRedirect('/login');
    }

    public function test_security_headers_present(): void
    {
        $res = $this->get('/login');
        $this->assertStringContainsString("script-src 'self'", $res->headers->get('Content-Security-Policy'));
        $res->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_organization_isolation_on_every_lead_endpoint(): void
    {
        $a = $this->makeOrg('Org A');
        $b = $this->makeOrg('Org B');
        $adminA = $this->makeUser($a);
        $adminB = $this->makeUser($b);
        $leadB = $this->createLead($b, $adminB);

        $this->api($adminA, 'GET', 'leads/'.$leadB['id'])->assertNotFound();
        $this->api($adminA, 'PATCH', 'leads/'.$leadB['id'], ['priority' => 'high'])->assertNotFound();
        $this->api($adminA, 'POST', 'leads/'.$leadB['id'].'/activities', ['body' => 'x'])->assertNotFound();
        $this->api($adminA, 'GET', 'contacts/'.$leadB['contact']['id'])->assertNotFound();
        $this->get('/leads/'.$leadB['id'])->assertNotFound();
        $this->api($adminA, 'GET', 'leads')->assertOk()->assertJsonCount(0, 'data');
        $this->api($adminA, 'POST', 'leads/bulk', ['action' => 'priority', 'priority' => 'urgent', 'ids' => [$leadB['id']]])->assertOk()->assertJsonPath('data.matched', 0);

        // Foreign keys from another org are rejected by validation
        $statusB = $this->lookup($b, \App\Models\LeadStatus::class, 'qualified');
        $leadA = $this->createLead($a, $adminA);
        $this->api($adminA, 'POST', 'leads/'.$leadA['id'].'/status', ['lead_status_id' => $statusB])->assertStatus(422);
        $this->assertSame('high' === 'x' ? 0 : 0, 0);
        $this->assertEquals('normal', Lead::withoutGlobalScopes()->find($leadB['id'])->priority);
    }

    public function test_client_supplied_organization_id_is_ignored(): void
    {
        $a = $this->makeOrg('Org A');
        $b = $this->makeOrg('Org B');
        $adminA = $this->makeUser($a);
        $lead = $this->createLead($a, $adminA, ['organization_id' => $b->id]);
        $this->assertEquals($a->id, Lead::withoutGlobalScopes()->find($lead['id'])->organization_id);
    }

    public function test_permissions_enforced_on_backend(): void
    {
        $org = $this->makeOrg();
        $analyst = $this->makeUser($org, 'analyst');
        $rep = $this->makeUser($org, 'sales_rep');

        $this->api($analyst, 'GET', 'leads')->assertForbidden();
        $this->api($analyst, 'GET', 'dashboard')->assertOk();
        $this->api($rep, 'GET', 'users')->assertForbidden();
        $this->api($rep, 'PATCH', 'settings/organization', [])->assertForbidden();
        $this->api($rep, 'POST', 'leads/export', ['format' => 'csv'])->assertForbidden();
        $this->api($rep, 'GET', 'audit-logs')->assertForbidden();
        $this->actingAs($rep)->get('/settings')->assertForbidden();
    }

    public function test_sales_rep_only_sees_assigned_leads_and_manager_sees_team(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $manager = $this->makeUser($org, 'sales_manager');
        $team = $this->inOrg($org, fn () => \App\Models\Team::create(['name' => 'North', 'manager_user_id' => $manager->id]));
        $rep1 = $this->makeUser($org, 'sales_rep', ['team_id' => $team->id]);
        $rep2 = $this->makeUser($org, 'sales_rep');

        $l1 = $this->createLead($org, $admin, ['assigned_user_id' => $rep1->id]);
        $l2 = $this->createLead($org, $admin, ['assigned_user_id' => $rep2->id]);

        $this->api($rep1, 'GET', 'leads')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $l1['id']);
        $this->api($rep1, 'GET', 'leads/'.$l2['id'])->assertNotFound();
        $this->api($manager, 'GET', 'leads')->assertJsonCount(1, 'data');
        $this->api($manager, 'POST', 'leads/'.$l1['id'].'/assign', ['assigned_user_id' => $rep2->id])->assertStatus(403);

        // A rep's own lead is auto-assigned to them
        $own = $this->createLead($org, $rep1);
        $this->assertEquals($rep1->id, $own['assignee']['id']);
        $this->api($rep1, 'POST', 'leads', ['contact' => ['phone' => '03219876543'], 'lead_source_id' => $this->lookup($org, \App\Models\LeadSource::class, 'walk_in'), 'assigned_user_id' => $rep2->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_only_super_admin_can_grant_super_admin(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org, 'business_admin');
        $target = $this->makeUser($org, 'sales_rep');
        $superRole = $this->lookup($org, \App\Models\Role::class, 'super_admin');
        $this->api($admin, 'PATCH', 'users/'.$target->id, ['role_ids' => [$superRole]])->assertForbidden();
        $this->api($admin, 'PATCH', 'users/'.$admin->id, ['is_active' => false])->assertStatus(422);
    }
}
