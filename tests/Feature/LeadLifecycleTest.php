<?php

namespace Tests\Feature;

use App\Models\CallOutcome;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use Tests\TestCase;

class LeadLifecycleTest extends TestCase
{
    public function test_phone_is_normalized_and_original_preserved(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $lead = $this->createLead($org, $admin, ['contact' => ['phone' => '0300-123 4567']]);
        $this->assertSame('+923001234567', $lead['contact']['phone']);
        $this->assertSame('0300-123 4567', $lead['contact']['phone_raw']);

        $uk = $this->createLead($org, $admin, ['contact' => ['phone' => '07911 123456', 'phone_region' => 'GB']]);
        $this->assertSame('+447911123456', $uk['contact']['phone']);
        $uae = $this->createLead($org, $admin, ['contact' => ['phone' => '00971501234567']]);
        $this->assertSame('+971501234567', $uae['contact']['phone']);
    }

    public function test_invalid_phone_and_missing_identifier_are_rejected(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $src = $this->lookup($org, LeadSource::class, 'whatsapp');
        $this->api($admin, 'POST', 'leads', ['contact' => ['phone' => '12'], 'lead_source_id' => $src])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['contact.phone']]]);
        $this->api($admin, 'POST', 'leads', ['contact' => ['name' => 'No phone'], 'lead_source_id' => $src])->assertStatus(422);
    }

    public function test_duplicate_contact_is_linked_and_open_lead_conflict_detected(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $first = $this->createLead($org, $admin, ['contact' => ['phone' => '03001112233']]);

        $res = $this->api($admin, 'POST', 'leads', ['contact' => ['phone' => '+92 300 1112233'], 'lead_source_id' => $this->lookup($org, LeadSource::class, 'whatsapp')]);
        $res->assertStatus(409)->assertJsonPath('error.code', 'duplicate_open_lead')->assertJsonPath('error.details.existing_lead_id', $first['id']);

        $second = $this->createLead($org, $admin, ['contact' => ['phone' => '923001112233'], 'allow_duplicate_open_lead' => true]);
        $this->assertSame($first['contact']['id'], $second['contact']['id']);
        $this->assertSame(1, $this->inOrg($org, fn () => Contact::count()));

        $match = $this->api($admin, 'GET', 'contacts/match?phone=03001112233')->assertOk();
        $match->assertJsonPath('data.matches.0.contact_id', $first['contact']['id'])->assertJsonPath('data.matches.0.has_open_lead', true);
    }

    public function test_status_change_requires_reason_and_records_history(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $lead = $this->createLead($org, $admin);
        $lost = $this->lookup($org, LeadStatus::class, 'lost');
        $this->api($admin, 'POST', "leads/{$lead['id']}/status", ['lead_status_id' => $lost])->assertStatus(422)->assertJsonPath('error.code', 'reason_required');
        $this->api($admin, 'POST', "leads/{$lead['id']}/status", ['lead_status_id' => $this->lookup($org, LeadStatus::class, 'qualified')])->assertOk();
        $this->api($admin, 'POST', "leads/{$lead['id']}/status", ['lead_status_id' => $this->lookup($org, LeadStatus::class, 'won')])->assertOk()->assertJsonPath('data.status.category', 'won');

        $l = $this->inOrg($org, fn () => Lead::find($lead['id']));
        $this->assertNotNull($l->qualified_at);
        $this->assertNotNull($l->converted_at);
        $this->assertSame(2, $l->statusHistory()->count());

        // Reopening a won deal clears the conversion so metrics stay honest
        $this->api($admin, 'POST', "leads/{$lead['id']}/status", ['lead_status_id' => $this->lookup($org, LeadStatus::class, 'proposal')])->assertOk();
        $this->assertNull($l->fresh()->converted_at);
    }

    public function test_do_not_contact_opts_out_and_blocks_follow_ups(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $lead = $this->createLead($org, $admin);
        $this->api($admin, 'POST', "leads/{$lead['id']}/follow-ups", ['scheduled_at' => now()->addDay()->toIso8601String()])->assertCreated();

        $dnc = $this->lookup($org, LeadStatus::class, 'do_not_contact');
        $this->api($admin, 'POST', "leads/{$lead['id']}/status", ['lead_status_id' => $dnc, 'reason' => 'Asked to stop calling'])->assertOk();

        $this->inOrg($org, function () use ($lead) {
            $c = Contact::find($lead['contact']['id']);
            $this->assertTrue($c->do_not_contact);
            $this->assertTrue($c->isSuppressed('whatsapp'));
            $this->assertSame(0, Lead::find($lead['id'])->callTasks()->where('status', 'pending')->count());
        });
        // reopen the lead: still suppressed → follow-ups refused
        $this->api($admin, 'POST', "leads/{$lead['id']}/status", ['lead_status_id' => $this->lookup($org, LeadStatus::class, 'responded')])->assertOk();
        $this->api($admin, 'POST', "leads/{$lead['id']}/follow-ups", ['scheduled_at' => now()->addDay()->toIso8601String()])->assertStatus(422)->assertJsonPath('error.code', 'contact_suppressed');
    }

    public function test_unanswered_call_never_marks_not_interested_and_callback_creates_task(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $lead = $this->createLead($org, $admin);
        $noAnswer = $this->lookup($org, CallOutcome::class, 'no_answer');
        for ($i = 0; $i < 3; $i++) {
            $this->api($admin, 'POST', "leads/{$lead['id']}/calls", ['call_outcome_id' => $noAnswer])->assertCreated();
        }
        $l = $this->inOrg($org, fn () => Lead::with('status')->find($lead['id']));
        $this->assertSame('contact_attempted', $l->status->key);
        $this->assertSame(3, $l->contact_attempts);
        $this->assertSame(3, $l->unanswered_attempts);
        $this->assertNull($l->first_responded_at);

        $callback = $this->lookup($org, CallOutcome::class, 'callback_requested');
        $this->api($admin, 'POST', "leads/{$lead['id']}/calls", ['call_outcome_id' => $callback])->assertStatus(422)->assertJsonPath('error.code', 'callback_time_required');
        $at = now()->addDays(2)->setTime(11, 0)->toIso8601String();
        $this->api($admin, 'POST', "leads/{$lead['id']}/calls", ['call_outcome_id' => $callback, 'callback_at' => $at])->assertCreated();
        $l->refresh()->load('status');
        $this->assertSame('responded', $l->status->key);
        $this->assertSame(0, $l->unanswered_attempts);
        $this->assertNotNull($l->first_responded_at);
        $this->assertNotNull($l->next_follow_up_at);
        $this->assertSame(1, $l->callTasks()->where('status', 'pending')->where('purpose', 'Requested callback')->count());
    }

    public function test_local_time_inputs_are_interpreted_in_organization_timezone(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $lead = $this->createLead($org, $admin);
        $local = now('Asia/Karachi')->addDay()->setTime(11, 0)->format('Y-m-d\TH:i');
        $res = $this->api($admin, 'POST', "leads/{$lead['id']}/follow-ups", ['scheduled_at' => $local])->assertCreated();
        $this->assertSame(now('Asia/Karachi')->addDay()->setTime(11, 0)->utc()->toIso8601ZuluString(), $res->json('data.scheduled_at'));
    }

    public function test_follow_up_queues_and_overdue_count(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $lead = $this->createLead($org, $admin);
        $this->api($admin, 'POST', "leads/{$lead['id']}/follow-ups", ['scheduled_at' => now()->addHours(2)->toIso8601String()])->assertCreated();
        $this->inOrg($org, fn () => \App\Models\CallTask::query()->update(['scheduled_at' => now()->subHours(3)]));
        $this->api($admin, 'GET', 'call-tasks?view=overdue')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.counts.overdue', 1);
        $this->api($admin, 'GET', 'dashboard?range=today')->assertOk()->assertJsonPath('data.summary.overdue_follow_ups', 1);

        $this->inOrg($org, fn () => \App\Models\CallTask::query()->update(['scheduled_at' => now()->subHours(30)]));
        $this->artisan('followups:mark-missed')->assertSuccessful();
        $this->api($admin, 'GET', 'call-tasks?view=missed')->assertJsonCount(1, 'data');
    }

    public function test_timeline_records_actions_and_bulk_assign(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $rep = $this->makeUser($org, 'sales_rep');
        $a = $this->createLead($org, $admin);
        $b = $this->createLead($org, $admin);
        $this->api($admin, 'POST', "leads/{$a['id']}/activities", ['body' => 'Asked for brochure'])->assertCreated();
        $this->api($admin, 'POST', 'leads/bulk', ['action' => 'assign', 'assigned_user_id' => $rep->id, 'ids' => [$a['id'], $b['id']]])->assertOk()->assertJsonPath('data.updated', 2);
        $types = collect($this->api($admin, 'GET', "leads/{$a['id']}/timeline")->json('data'))->pluck('type');
        $this->assertTrue($types->contains('note') && $types->contains('assigned') && $types->contains('lead_created'));

        $this->api($admin, 'POST', 'leads/bulk', ['action' => 'archive', 'ids' => [$a['id']], 'reason' => 'test'])->assertStatus(422)->assertJsonPath('error.code', 'confirmation_required');
        $this->api($admin, 'POST', 'leads/bulk', ['action' => 'archive', 'ids' => [$a['id']], 'reason' => 'test', 'confirm' => true])->assertOk();
        $this->api($admin, 'GET', 'leads')->assertJsonCount(1, 'data');
    }

    public function test_contact_merge_is_explicit_and_preserves_history(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org);
        $one = $this->createLead($org, $admin, ['contact' => ['name' => 'Ali Khan', 'phone' => '03001234567']]);
        $two = $this->createLead($org, $admin, ['contact' => ['name' => 'Ali Khan', 'phone' => '03217654321', 'email' => 'ali@example.com']]);

        $dups = $this->api($admin, 'GET', "contacts/{$one['contact']['id']}/duplicates")->assertOk();
        $dups->assertJsonPath('data.0.strength', 'weak');
        $this->assertSame(2, $this->inOrg($org, fn () => Contact::count())); // never auto-merged

        $this->api($admin, 'POST', "contacts/{$one['contact']['id']}/merge", ['source_contact_id' => $two['contact']['id']])->assertStatus(422); // confirm required
        $this->api($admin, 'POST', "contacts/{$one['contact']['id']}/merge", ['source_contact_id' => $two['contact']['id'], 'confirm' => true])->assertOk()
            ->assertJsonPath('data.email', 'ali@example.com')->assertJsonPath('data.whatsapp', '+923217654321');
        $this->inOrg($org, function () use ($one, $two) {
            $this->assertSame($one['contact']['id'], Lead::find($two['id'])->contact_id);
            $this->assertSame(1, \App\Models\AuditLog::where('action', 'contact.merged')->count());
        });
        $rep = $this->makeUser($org, 'sales_rep');
        $this->api($rep, 'POST', "contacts/{$one['contact']['id']}/merge", ['source_contact_id' => 1, 'confirm' => true])->assertForbidden();
    }
}
