<?php

namespace Tests\Feature;

use App\Models\Notification;
use Tests\TestCase;

class ModulesTest extends TestCase
{
    public function test_analytics_requires_reports_permission_and_returns_sections(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $rep = $this->makeUser($org, 'sales_rep');
        $this->createLead($org, $admin);
        $this->api($rep, 'GET', 'analytics')->assertForbidden();
        $d = $this->api($admin, 'GET', 'analytics?range=last_7_days')->assertOk()->json('data');
        $this->assertSame(1, $d['sources'][0]['leads']);
        $this->assertNull($d['sources'][0]['revenue']);
        $this->assertArrayHasKey('resolution_rate', $d['ai']);
        $csv = $this->actingAs($admin)->get('/api/v1/analytics/export?section=sources&range=last_7_days')->assertOk()->streamedContent();
        $this->assertStringContainsString('Source,Leads', $csv);
        $xlsx = $this->actingAs($admin)->get('/api/v1/analytics/export?section=sources&range=last_7_days&format=xlsx')->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->streamedContent();
        $this->assertStringStartsWith('PK', $xlsx);
    }

    public function test_notifications_are_private_and_markable(): void
    {
        $org = $this->makeOrg();
        $a = $this->makeUser($org);
        $b = $this->makeUser($org, 'sales_rep');
        $this->inOrg($org, fn () => app(\App\Services\NotificationService::class)->notify($a->id, 'x', 'Hello', null, '/', 'k1'));
        $this->inOrg($org, fn () => app(\App\Services\NotificationService::class)->notify($a->id, 'x', 'Hello again', null, '/', 'k1')); // deduped
        $this->api($a, 'GET', 'notifications')->assertJsonCount(1, 'data')->assertJsonPath('meta.unread', 1);
        $this->api($b, 'GET', 'notifications')->assertJsonCount(0, 'data');
        $this->api($a, 'POST', 'notifications/read')->assertOk();
        $this->assertNotNull(Notification::first()->read_at);
    }

    public function test_profile_update_and_password_change_requires_current_password(): void
    {
        $org = $this->makeOrg();
        $u = $this->makeUser($org, 'sales_rep');
        $this->api($u, 'PATCH', 'profile', ['name' => 'New Name', 'locale' => 'ar', 'timezone' => 'Asia/Dubai'])->assertOk();
        $this->assertSame('ar', $u->fresh()->locale);
        $this->api($u, 'POST', 'profile/password', ['current_password' => 'wrong', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertStatus(422);
        $this->api($u, 'POST', 'profile/password', ['current_password' => 'Password123!', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertOk();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewPassword123', $u->fresh()->password));
    }

    public function test_business_settings_and_logo_validation(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $hours = ['mon' => ['09:00', '17:00'], 'tue' => ['09:00', '17:00'], 'wed' => null, 'thu' => ['09:00', '17:00'], 'fri' => ['14:00', '18:00'], 'sat' => null, 'sun' => null];
        $this->api($admin, 'PATCH', 'settings/business', ['industry' => 'healthcare', 'brand_color' => '#0f766e', 'default_locale' => 'ur', 'business_hours' => $hours,
            'auto_assign' => ['enabled' => true], 'scoring_weights' => ['budget_stated' => 30]])->assertOk();
        $this->assertSame('#0f766e', $org->fresh()->brand_color);
        $this->assertSame(30, $this->inOrg($org, fn () => app(\App\Services\LeadScoring::class)->weights()['budget_stated']));
        $svg = \Illuminate\Http\UploadedFile::fake()->createWithContent('logo.svg', '<svg onload="alert(1)"></svg>');
        $this->actingAs($admin)->post('/api/v1/settings/logo', ['logo' => $svg], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($admin)->get('/branding/theme.css')->assertOk()->assertSee('--lf-accent:#0f766e', false);
    }

    public function test_ai_assistant_settings_validation_and_permissions(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $rep = $this->makeUser($org, 'sales_rep');
        $this->api($rep, 'GET', 'ai/assistant')->assertForbidden();
        $a = $this->api($admin, 'GET', 'ai/assistant')->assertOk()->json('data.assistant');
        $this->api($admin, 'PUT', 'ai/assistant', array_merge($a, ['model' => 'bad model; drop', 'languages' => ['en']]))->assertStatus(422);
        $this->api($admin, 'PUT', 'ai/assistant', array_merge($a, ['model' => 'deepseek-v4-flash', 'provider' => 'deepseek', 'mode' => 'auto']))->assertOk()->assertJsonPath('data.mode', 'auto');
        $this->api($admin, 'GET', 'ai/models?provider=deepseek')->assertStatus(409); // not connected
        $this->api($admin, 'POST', 'ai/rules', ['name' => 'x', 'trigger' => 'keyword', 'response' => 'y'])->assertStatus(422);
    }
}
