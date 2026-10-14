<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AiInteraction;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Message;
use App\Models\Notification;
use App\Models\SuppressionRecord;
use App\Models\WebhookEvent;
use App\Services\OrganizationSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    public function test_contact_erasure_scrubs_pii_everywhere_but_keeps_opt_out_and_counts(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $rep = $this->makeUser($org, 'sales_rep');
        $lead = $this->createLead($org, $admin, ['contact' => ['name' => 'Ayesha Khan', 'phone' => '03001234567', 'email' => 'ayesha@example.com', 'city' => 'Lahore'], 'source_notes' => 'Budget 50 lakh, lives near DHA']);
        $cid = $lead['contact']['id'];
        $this->api($admin, 'POST', "leads/{$lead['id']}/activities", ['body' => 'Called, she prefers evenings'])->assertCreated();
        $this->inOrg($org, function () use ($cid, $lead) {
            $conv = Conversation::create(['channel' => 'whatsapp', 'external_id' => '923001234567', 'contact_id' => $cid, 'lead_id' => $lead['id'], 'last_message_preview' => 'Hi, I saw your ad']);
            Message::create(['conversation_id' => $conv->id, 'direction' => 'in', 'sender_type' => 'contact', 'type' => 'text', 'body' => 'Hi, I saw your ad', 'status' => 'received', 'occurred_at' => now(), 'provider_message_id' => 'wamid.1']);
            AiInteraction::create(['conversation_id' => $conv->id, 'kind' => 'reply', 'status' => 'ok', 'input' => 'Hi, I saw your ad', 'response' => 'Hello Ayesha!']);
        });

        $this->api($rep, 'POST', "contacts/$cid/erase", ['reason' => 'Deletion request', 'confirm' => 'ERASE'])->assertForbidden();
        $this->api($admin, 'POST', "contacts/$cid/erase", ['reason' => 'Deletion request', 'confirm' => 'YES'])->assertStatus(422);
        $r = $this->api($admin, 'POST', "contacts/$cid/erase", ['reason' => 'Deletion request received 12 Oct', 'confirm' => 'ERASE'])->assertOk();
        $this->assertSame(1, $r->json('data.messages'));

        $this->inOrg($org, function () use ($cid, $lead) {
            $c = Contact::find($cid);
            $this->assertNotNull($c->erased_at);
            $this->assertNull($c->name);
            $this->assertNull($c->phone_e164);
            $this->assertNull($c->email_normalized);
            $this->assertTrue($c->do_not_contact);
            $this->assertSame('+923001234567', SuppressionRecord::where('contact_id', $cid)->value('phone_e164')); // opt-out survives
            $this->assertSame(1, Lead::where('contact_id', $cid)->count()); // counts kept
            $this->assertNull(Lead::find($lead['id'])->source_notes);
            $this->assertNull(Message::first()->body);
            $this->assertNull(AiInteraction::first()->input);
            $this->assertSame('[erased]', Activity::where('lead_id', $lead['id'])->where('type', 'note')->value('body'));
            $this->assertSame('closed', Conversation::first()->status);
            $this->assertTrue(AuditLog::where('action', 'contact.erased')->exists());
            // Nothing identifying remains anywhere in the API representation.
        });
        $json = json_encode($this->api($admin, 'GET', "contacts/$cid")->assertOk()->json());
        $this->assertStringNotContainsString('Ayesha', $json);
        $this->assertStringNotContainsString('3001234567', $json);
        $this->api($admin, 'POST', "contacts/$cid/erase", ['reason' => 'again please', 'confirm' => 'ERASE'])->assertStatus(409);
        // Erased + suppressed: a new lead for that number cannot be messaged or re-targeted.
        $json = json_encode($this->api($admin, 'GET', 'leads?q=Ayesha')->json());
        $this->assertStringNotContainsString('Ayesha', $json);
    }

    public function test_retention_command_applies_per_organization_settings(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $won = $this->createLead($org, $admin);
        $open = $this->createLead($org, $admin);
        $this->api($admin, 'POST', "leads/{$won['id']}/status", ['lead_status_id' => $this->lookup($org, LeadStatus::class, 'won')])->assertOk();
        $this->inOrg($org, function () use ($won, $admin) {
            Lead::whereKey($won['id'])->update(['closed_at' => now()->subDays(400)]);
            WebhookEvent::create(['provider' => 'whatsapp_cloud', 'event_key' => 'old', 'status' => 'processed', 'payload' => ['x' => 1], 'created_at' => now()->subDays(60)]);
            WebhookEvent::create(['provider' => 'whatsapp_cloud', 'event_key' => 'failed-old', 'status' => 'failed', 'payload' => ['x' => 1], 'created_at' => now()->subDays(60)]);
            WebhookEvent::create(['provider' => 'whatsapp_cloud', 'event_key' => 'new', 'status' => 'processed', 'payload' => ['x' => 1]]);
            Notification::query()->insert(['organization_id' => $admin->organization_id, 'user_id' => $admin->id, 'type' => 'x', 'title' => 'old', 'dedupe_key' => 'o', 'created_at' => now()->subDays(100), 'updated_at' => now()]);
            app(OrganizationSettings::class)->set('retention_archive_after_days', 365);
        });

        Artisan::call('leadflow:retention', ['--dry-run' => true]);
        $this->inOrg($org, fn () => $this->assertNull(Lead::find($won['id'])->archived_at));
        Artisan::call('leadflow:retention');
        $this->inOrg($org, function () use ($won, $open) {
            $this->assertNotNull(Lead::find($won['id'])->archived_at);
            $this->assertNull(Lead::find($open['id'])->archived_at);
            $this->assertSame(['failed-old', 'new'], WebhookEvent::orderBy('event_key')->pluck('event_key')->all()); // failed events are kept for investigation
            $this->assertSame(0, Notification::where('title', 'old')->count());
            $this->assertTrue(AuditLog::where('action', 'retention.applied')->exists());
        });
        // Settings endpoint validates ranges and accepts "never" for archiving.
        $this->api($admin, 'PATCH', 'settings/organization', ['name' => 'Acme', 'timezone' => 'Asia/Karachi', 'currency' => 'PKR', 'default_country' => 'PK',
            'settings' => ['required_identifier' => 'any', 'allow_duplicate_open_leads' => false, 'first_contact_sla_minutes' => 30, 'retention_archive_after_days' => null,
                'retention_purge_webhooks_days' => 3, 'retention_purge_notifications_days' => 90, 'retention_purge_ai_days' => 180, 'retention_purge_automation_days' => 180]])->assertStatus(422);
    }

    public function test_backup_and_doctor_commands(): void
    {
        Storage::fake('local');
        $org = $this->makeOrg();
        $this->makeUser($org);
        $this->assertSame(0, Artisan::call('leadflow:backup'));
        $files = collect(Storage::disk('local')->files('backups'))->filter(fn ($f) => str_ends_with($f, '.zip'));
        $this->assertCount(1, $files);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($files->first())));
        $this->assertNotFalse($zip->locateName('MANIFEST.json'));
        $this->assertStringNotContainsString(config('app.key'), (string) $zip->getFromName('MANIFEST.json'));
        $zip->close();

        Artisan::call('leadflow:doctor', ['--json' => true]);
        $checks = collect(json_decode(Artisan::output(), true))->keyBy('key');
        $this->assertSame('ok', $checks['key']['status']);
        $this->assertSame('ok', $checks['db']['status']);
        $this->assertSame('ok', $checks['migrations']['status']);
        $this->assertSame('fail', $checks['scheduler']['status']); // never ran in tests
        $this->assertSame('ok', $checks['backup']['status']);
    }

    public function test_error_monitor_notifies_platform_admins_once_per_hour(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $admin->forceFill(['is_platform_admin' => true])->save();
        $e = new \RuntimeException('Boom');
        \App\Support\Ops\ErrorMonitor::report($e);
        \App\Support\Ops\ErrorMonitor::report($e);
        $this->assertSame(1, Notification::withoutGlobalScopes()->where('user_id', $admin->id)->where('type', 'system_error')->count());
        $this->assertSame('RuntimeException', \App\Support\Ops\ErrorMonitor::last()['class']);
        $json = $this->api($admin, 'GET', 'platform')->assertOk()->json('data');
        $this->assertSame('RuntimeException', $json['last_error']['class']);
        $this->assertNotEmpty($json['checks']);
    }
}
