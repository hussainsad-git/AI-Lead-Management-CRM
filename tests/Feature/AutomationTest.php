<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\CallOutcome;
use App\Models\CallTask;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Notification;
use App\Services\Automation\AutomationEngine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    private function rule($org, array $attrs): AutomationRule
    {
        return $this->inOrg($org, fn () => AutomationRule::create($attrs + ['name' => 'R', 'delay_minutes' => 0, 'is_active' => true, 'conditions' => ['match' => 'all', 'rules' => []]]));
    }

    public function test_new_organizations_get_default_rules_with_first_contact_disabled(): void
    {
        $org = $this->makeOrg();
        $rules = $this->inOrg($org, fn () => AutomationRule::pluck('is_active', 'template_key'));
        $this->assertCount(5, $rules);
        $this->assertFalse($rules['first_contact']);
        $this->assertTrue($rules['sla_uncontacted']);
    }

    public function test_lead_created_rule_assigns_round_robin_and_creates_task_once(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $rep = $this->makeUser($org, 'sales_rep');
        $this->inOrg($org, fn () => AutomationRule::where('template_key', 'first_contact')->update(['is_active' => true]));

        $lead = $this->createLead($org, $admin);
        $this->inOrg($org, function () use ($lead, $rep) {
            $l = Lead::find($lead['id']);
            $this->assertSame($rep->id, $l->assigned_user_id);
            $this->assertSame(1, CallTask::where('lead_id', $l->id)->where('status', 'pending')->count());
            $this->assertStringContainsString('First contact: Test Person', CallTask::where('lead_id', $l->id)->value('purpose'));
            $this->assertTrue(Notification::where('user_id', $rep->id)->where('type', 'lead_assigned')->exists());

            // Re-delivering the same event is a no-op (idempotent by rule + event id).
            app(AutomationEngine::class)->run('lead.created', $l, 'lead:'.$l->id);
            $this->assertSame(1, CallTask::where('lead_id', $l->id)->count());
            $this->assertSame(1, AutomationExecution::count());
        });
    }

    public function test_conditions_gate_actions(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $this->rule($org, ['trigger' => 'lead.created', 'conditions' => ['match' => 'all', 'rules' => [['field' => 'city', 'op' => 'contains', 'value' => 'lahore']]],
            'actions' => [['type' => 'set_priority', 'priority' => 'urgent'], ['type' => 'add_tag', 'tag' => 'Lahore']]]);
        $a = $this->createLead($org, $admin, ['contact' => ['city' => 'Lahore Cantt']]);
        $b = $this->createLead($org, $admin, ['contact' => ['city' => 'Karachi']]);
        $this->inOrg($org, function () use ($a, $b) {
            $this->assertSame('urgent', Lead::find($a['id'])->priority);
            $this->assertSame(['Lahore'], Lead::find($a['id'])->tags->pluck('name')->all());
            $this->assertNotSame('urgent', Lead::find($b['id'])->priority);
        });
    }

    public function test_qualified_and_call_logged_triggers(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $lead = $this->createLead($org, $admin, ['assigned_user_id' => $admin->id]);
        $this->api($admin, 'POST', "leads/{$lead['id']}/status", ['lead_status_id' => $this->lookup($org, LeadStatus::class, 'qualified')])->assertOk();
        $this->inOrg($org, fn () => $this->assertTrue(Notification::where('user_id', $admin->id)->where('title', 'like', 'Qualified%')->exists()));

        // Default "3 unanswered calls" rule.
        $noAnswer = $this->lookup($org, CallOutcome::class, 'no_answer');
        foreach (range(1, 3) as $i) {
            $this->api($admin, 'POST', "leads/{$lead['id']}/calls", ['call_outcome_id' => $noAnswer])->assertCreated();
        }
        $this->inOrg($org, fn () => $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'like', '%3 unanswered calls%')->count()));
    }

    public function test_loop_guard_stops_chained_status_rules(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $a = $this->lookup($org, LeadStatus::class, 'contact_pending');
        $b = $this->lookup($org, LeadStatus::class, 'interested');
        // Two rules that bounce the lead between stages forever, if allowed.
        $this->rule($org, ['trigger' => 'lead.status_changed', 'conditions' => ['match' => 'all', 'rules' => [['field' => 'status', 'op' => 'in', 'value' => ['contact_pending']]]], 'actions' => [['type' => 'move_stage', 'status_id' => $b]]]);
        $this->rule($org, ['trigger' => 'lead.status_changed', 'conditions' => ['match' => 'all', 'rules' => [['field' => 'status', 'op' => 'in', 'value' => ['interested']]]], 'actions' => [['type' => 'move_stage', 'status_id' => $a]]]);
        $lead = $this->createLead($org, $admin);
        $this->api($admin, 'POST', "leads/{$lead['id']}/status", ['lead_status_id' => $a])->assertOk();
        $this->inOrg($org, function () {
            $this->assertSame(1, AutomationExecution::where('status', 'skipped')->count());
            $this->assertLessThanOrEqual(config('automation.max_depth') + 1, AutomationExecution::count());
        });
    }

    public function test_sla_scan_escalates_once_and_reminders_fire(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $rep = $this->makeUser($org, 'sales_rep');
        $lead = $this->createLead($org, $admin, ['assigned_user_id' => $rep->id]);
        $this->inOrg($org, fn () => Lead::whereKey($lead['id'])->update(['created_at' => now()->subHours(2)]));
        Artisan::call('automation:scan');
        Artisan::call('automation:scan');
        $this->inOrg($org, function () use ($rep, $admin) {
            $this->assertSame(1, Notification::where('user_id', $rep->id)->where('title', 'like', 'SLA missed%')->count());
            $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'like', 'SLA missed%')->count());
        });

        $this->api($rep, 'POST', "leads/{$lead['id']}/follow-ups", ['scheduled_at' => now()->setTimezone($org->timezone)->addMinutes(10)->format('Y-m-d\TH:i'), 'purpose' => 'Requested callback'])->assertCreated();
        Artisan::call('automation:scan');
        Artisan::call('automation:scan');
        $this->inOrg($org, fn () => $this->assertSame(1, Notification::where('user_id', $rep->id)->where('type', 'callback_due')->count()));
    }

    public function test_opt_out_trigger_cannot_schedule_contact_and_api_is_permissioned(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $rep = $this->makeUser($org, 'sales_rep');
        $this->api($rep, 'GET', 'automation/rules')->assertForbidden();
        $this->api($admin, 'POST', 'automation/rules', ['trigger' => 'lead.opted_out', 'actions' => [['type' => 'create_task', 'purpose' => 'x']]])
            ->assertStatus(422);
        $this->api($admin, 'POST', 'automation/rules', ['trigger' => 'lead.uncontacted', 'delay_minutes' => 1, 'actions' => [['type' => 'notify_owner', 'message' => 'x']]])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $res = $this->api($admin, 'POST', 'automation/rules', ['name' => 'Big budget', 'trigger' => 'lead.created',
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'budget_amount', 'op' => 'gte', 'value' => '5000000']]],
            'actions' => [['type' => 'set_priority', 'priority' => 'high']]])->assertCreated();
        $id = $res->json('data.id');
        $this->api($admin, 'PATCH', "automation/rules/$id", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.name', 'Big budget');

        $lead = $this->createLead($org, $admin, ['budget_amount' => 6000000]);
        $test = $this->api($admin, 'POST', "automation/rules/$id/test", ['lead_id' => $lead['id']])->assertOk();
        $this->assertTrue($test->json('data.would_run'));
        $this->inOrg($org, fn () => $this->assertSame(0, AutomationExecution::where('automation_rule_id', $id)->count())); // dry run, and rule inactive

        // A rule from another organization is invisible.
        $other = $this->makeOrg('Other');
        $otherAdmin = $this->makeUser($other);
        $this->api($otherAdmin, 'PATCH', "automation/rules/$id", ['is_active' => true])->assertNotFound();
    }

    public function test_email_notifications_respect_preferences_and_send_once(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $rep = $this->makeUser($org, 'sales_rep');
        $notify = fn ($u, $type, $key) => $this->inOrg($org, fn () => app(\App\Services\NotificationService::class)->notify($u->id, $type, 'Hello', 'Body', '/leads/1', $key));

        $notify($rep, 'lead_assigned', 'a1');
        $notify($rep, 'lead_assigned', 'a1'); // duplicate event
        $notify($rep, 'whatsapp_in', 'w1');   // off by default
        Mail::assertSent(NotificationMail::class, 1);

        $this->api($rep, 'PUT', 'profile/notifications', ['email' => ['lead_assigned' => false, 'whatsapp_in' => true, 'bogus' => true]])->assertOk();
        $this->assertArrayNotHasKey('bogus', $rep->fresh()->notification_prefs['email']);
        $notify($rep, 'lead_assigned', 'a2');
        $notify($rep, 'whatsapp_in', 'w2');
        Mail::assertSent(NotificationMail::class, 2);
        $this->inOrg($org, fn () => $this->assertSame(2, Notification::whereNotNull('emailed_at')->count()));

        // With the log mailer (default install), nothing is queued for email.
        config(['mail.default' => 'log']);
        $notify($admin, 'lead_assigned', 'a3');
        Mail::assertSent(NotificationMail::class, 2);
        $this->api($admin, 'GET', 'profile')->assertJsonPath('data.notifications.email_enabled', false);
    }

    public function test_webhook_action_signs_requests_and_rejects_private_urls(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        foreach (['http://example.com/hook', 'https://localhost/hook', 'https://127.0.0.1/hook', 'https://10.0.0.5/hook', 'https://user:pw@example.com/x'] as $bad) {
            $this->api($admin, 'POST', 'automation/rules', ['trigger' => 'lead.created', 'actions' => [['type' => 'call_webhook', 'url' => $bad]]])->assertStatus(422);
        }
        \Illuminate\Support\Facades\Http::fake(['hooks.example.com/*' => \Illuminate\Support\Facades\Http::response('ok', 200)]);
        $this->api($admin, 'POST', 'automation/rules', ['name' => 'Zap', 'trigger' => 'lead.created', 'actions' => [['type' => 'call_webhook', 'url' => 'https://hooks.example.com/catch/1', 'secret' => 'shh']]])->assertCreated();
        $lead = $this->createLead($org, $admin, ['contact' => ['name' => 'Hooked', 'phone' => '03001234567']]);
        \Illuminate\Support\Facades\Http::assertSent(function ($req) use ($lead) {
            $body = json_decode($req->body(), true);
            $sig = 'sha256='.hash_hmac('sha256', $req->header('X-LeadFlow-Timestamp')[0].'.'.$req->body(), 'shh');
            return $req->url() === 'https://hooks.example.com/catch/1' && $req->header('X-LeadFlow-Signature')[0] === $sig
                && $body['event'] === 'automation.lead.created' && $body['lead']['id'] === $lead['id'] && $body['lead']['contact']['name'] === 'Hooked'
                && ! isset($body['lead']['source_notes_internal']);
        });
        $this->inOrg($org, fn () => $this->assertSame('success', AutomationExecution::first()->status));
    }
}
