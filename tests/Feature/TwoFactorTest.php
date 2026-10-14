<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\OrganizationSettings;
use App\Support\Qr;
use App\Support\Totp;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    public function test_totp_matches_rfc_6238_vectors_and_qr_encodes(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, 1));
        $this->assertSame('081804', Totp::code($secret, intdiv(1111111109, 30)));
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));
        $m = Qr::matrix(Totp::uri('JBSWY3DPEHPK3PXP', 'a@b.c', 'LeadFlow'));
        $this->assertSame(count($m), count($m[0]));
        $this->assertSame(1, $m[0][0]); // finder pattern corner
        $this->assertStringStartsWith('<svg', Qr::svg('x'));
    }

    public function test_enrol_login_with_code_recovery_code_replay_and_admin_reset(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $user = $this->makeUser($org, 'sales_rep');

        $this->api($user, 'GET', 'profile/two-factor')->assertOk()->assertJsonPath('data.enabled', false);
        $begin = $this->api($user, 'POST', 'profile/two-factor/begin')->assertOk()->json('data');
        $this->assertStringStartsWith('otpauth://totp/', $begin['uri']);
        $this->assertStringContainsString('<svg', $begin['qr_svg']);
        $this->api($user, 'POST', 'profile/two-factor/confirm', ['code' => '000000'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_code');
        $codes = $this->api($user, 'POST', 'profile/two-factor/confirm', ['code' => Totp::code($begin['secret'])])->assertOk()->json('data.recovery_codes');
        $this->assertCount(10, $codes);
        $this->assertTrue((bool) $user->fresh()->totp_confirmed_at);
        $this->assertStringNotContainsString($begin['secret'], json_encode($this->api($user, 'GET', 'profile')->json())); // secret never echoed

        // Login now requires the second factor.
        auth()->guard('web')->forgetUser();
        $this->flushHeaders();
        app(\App\Support\Tenancy\CurrentOrganization::class)->set(null); // a fresh browser request has no tenant yet
        $this->post('/login', ['email' => $user->email, 'password' => 'Password123!'])->assertRedirect('/login/2fa');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
        $this->post('/login/2fa', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $good = Totp::code($user->fresh()->totp_secret, intdiv(time(), 30) + 1); // a fresh step (confirm consumed the current one)
        $this->post('/login/2fa', ['code' => $good])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        // Replay of the same code is rejected.
        $this->post('/logout');
        auth()->guard('web')->forgetUser();
        $this->flushHeaders();
        $this->post('/login', ['email' => $user->email, 'password' => 'Password123!'])->assertRedirect('/login/2fa');
        $this->post('/login/2fa', ['code' => $good])->assertSessionHasErrors('code');
        // Recovery code works once.
        $this->post('/login/2fa', ['code' => strtoupper($codes[0])])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(9, $this->api($user->fresh(), 'GET', 'profile/two-factor')->json('data.recovery_codes_left'));
        $this->post('/logout');
        auth()->guard('web')->forgetUser();
        $this->flushHeaders();
        $this->post('/login', ['email' => $user->email, 'password' => 'Password123!']);
        $this->post('/login/2fa', ['code' => $codes[0]])->assertSessionHasErrors('code');

        // Disable needs the password; admin reset works and is audited.
        $this->api($user, 'POST', 'profile/two-factor/disable', ['current_password' => 'nope'])->assertStatus(422);
        $this->api($user, 'POST', "users/{$user->id}/reset-two-factor")->assertForbidden();
        $this->api($admin, 'POST', "users/{$user->id}/reset-two-factor")->assertOk();
        $this->assertNull($user->fresh()->totp_confirmed_at);
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'auth.2fa_reset_by_admin')->exists());
    }

    public function test_organization_can_require_two_factor(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $rep = $this->makeUser($org, 'sales_rep');
        $tok = $this->api($admin, 'POST', 'api-tokens', ['name' => 'script'])->json('data.token'); // issued before the policy
        $this->inOrg($org, fn () => app(OrganizationSettings::class)->set('require_2fa', true));

        $this->api($rep, 'GET', 'leads')->assertStatus(403)->assertJsonPath('error.code', 'two_factor_required');
        $this->api($rep, 'GET', 'profile/two-factor')->assertOk()->assertJsonPath('data.required', true);
        $this->flushHeaders();
        $this->actingAs($rep)->get('/leads')->assertRedirect('/profile?setup=2fa');
        $this->actingAs($rep)->get('/profile')->assertOk();

        $begin = $this->api($rep, 'POST', 'profile/two-factor/begin')->assertOk()->json('data');
        $this->api($rep, 'POST', 'profile/two-factor/confirm', ['code' => Totp::code($begin['secret'])])->assertOk();
        $this->api($rep, 'GET', 'leads')->assertOk();
        $this->api($rep, 'POST', 'profile/two-factor/disable', ['current_password' => 'Password123!'])->assertStatus(422); // required by org
        // API tokens are unaffected by the requirement (they are not interactive logins).
        $this->api($admin, 'POST', 'api-tokens', ['name' => 'another'])->assertStatus(403); // admin must enrol too
        auth()->guard('web')->forgetUser();
        $this->flushHeaders();
        $this->getJson('/api/v1/leads', ['Authorization' => 'Bearer '.$tok])->assertOk();
    }
}
