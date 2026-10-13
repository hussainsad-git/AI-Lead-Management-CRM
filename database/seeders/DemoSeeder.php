<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\CampaignSpend;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\FollowUpService;
use App\Services\LeadService;
use App\Services\LeadStatusService;
use App\Services\OrganizationProvisioner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * SYNTHETIC demo data for local development and QA only. Every record is tagged
 * "Demo data" and the organization is named accordingly. Refuses to run in production.
 */
class DemoSeeder extends Seeder
{
    /** Synthetic WhatsApp history + knowledge base so the inbox, AI and analytics screens can be reviewed without Meta credentials. */
    private function demoConversations($org, $admin, array $reps): void
    {
        $kb = app(\App\Services\Ai\KnowledgeBase::class);
        $kb->addText('DEMO Price list – Phase 9', "5 marla plot: PKR 45 lakh, 20% down payment, 3-year quarterly installments.\n\n10 marla plot: PKR 85 lakh, 25% down payment, 3-year installments.\n\nPossession: within 18 months of booking.");
        $kb->addText('DEMO Office & visits', "Site office: Blue Area, Islamabad. Open Monday–Saturday 10am to 6pm.\n\nFree site visits daily at 11am and 4pm; book via WhatsApp.");
        $kb->addText('FAQ: Do you accept overseas Pakistanis?', "Q: Do you accept overseas Pakistanis?\nA: Yes. Overseas clients can book online and pay via bank transfer; documents are couriered.", 'faq');
        \App\Models\AutoReplyRule::create(['name' => 'Welcome (demo)', 'trigger' => 'first_message', 'response' => 'Assalam-o-Alaikum {name}! Thanks for contacting {business}. A property advisor will help you shortly.', 'priority' => 10]);
        \App\Models\AutoReplyRule::create(['name' => 'After hours (demo)', 'trigger' => 'outside_hours', 'response' => 'Thanks for your message! Our office is closed now; we will reply at 10am.', 'priority' => 20]);

        $script = [
            ['Ayesha (demo)', '923211110001', [['in', 'contact', 'Assalam o alaikum, 5 marla plot ki qeemat kya hai?'], ['out', 'auto_reply', 'Assalam-o-Alaikum Ayesha! Thanks for contacting Demo Realty (synthetic data). A property advisor will help you shortly.'],
                ['out', 'ai', 'Wa alaikum assalam! 5 marla plot PKR 45 lakh ka hai, 20% down payment aur 3 saal ki qiston ke sath. Kya aap site visit karna chahengi?'], ['in', 'contact', 'Ji, Saturday 11 baje theek hai'], ['out', 'user', 'Great, Saturday 11am confirmed. Our advisor Ali will call you.']], null, $reps[0]],
            ['Usman (demo)', '923211110002', [['in', 'contact', 'Do you accept overseas Pakistanis? I am in Dubai'], ['out', 'ai', 'Yes! Overseas clients can book online and pay by bank transfer; documents are couriered to you. Which plot size are you interested in?'], ['in', 'contact', '10 marla, budget around 90 lakh']], null, $reps[1]],
            ['Sara (demo)', '923211110003', [['in', 'contact', 'Is there a mosque and school inside the society?'], ['out', 'system', 'Thank you! A member of our team will reply to you shortly.']], 'Not in knowledge base: amenities', null],
        ];
        $i = 0;
        foreach ($script as [$name, $wa, $msgs, $handoff, $rep]) {
            $contact = \App\Models\Contact::create(['name' => $name, 'whatsapp_raw' => '+'.$wa, 'whatsapp_e164' => '+'.$wa, 'country_code' => 'PK', 'preferred_contact_method' => 'whatsapp']);
            $lead = app(\App\Services\LeadService::class)->create(['contact_id' => $contact->id, 'lead_source_id' => \App\Models\LeadSource::where('key', 'facebook_ads')->value('id'),
                'assigned_user_id' => $rep?->id, 'source_notes' => 'Click-to-WhatsApp ad (demo)', 'tags' => ['Demo data'], 'allow_duplicate_open_lead' => true], $admin, 'whatsapp');
            $start = now()->subHours(20 - $i * 6);
            $conv = \App\Models\Conversation::create(['contact_id' => $contact->id, 'lead_id' => $lead->id, 'external_id' => $wa, 'assigned_user_id' => $rep?->id,
                'handoff_at' => $handoff ? $start->copy()->addMinute() : null, 'handoff_reason' => $handoff, 'status' => $handoff ? 'pending' : 'open', 'language' => $i === 0 ? 'roman_ur' : 'en']);
            $t = $start->copy();
            foreach ($msgs as $k => [$dir, $who, $body]) {
                $inter = $who === 'ai' ? \App\Models\AiInteraction::create(['conversation_id' => $conv->id, 'lead_id' => $lead->id, 'kind' => 'reply', 'provider' => 'openai', 'model' => 'demo', 'status' => 'ok', 'outcome' => 'answered', 'input' => $msgs[$k - 1][2] ?? null, 'response' => $body, 'latency_ms' => 1400]) : null;
                \App\Models\Message::create(['conversation_id' => $conv->id, 'direction' => $dir, 'sender_type' => $who, 'user_id' => $who === 'user' ? $rep?->id : null,
                    'ai_interaction_id' => $inter?->id, 'type' => 'text', 'body' => $body, 'status' => $dir === 'in' ? 'received' : 'read', 'occurred_at' => $t->copy(), 'provider_message_id' => 'demo.'.$wa.'.'.$k]);
                $t->addMinutes(2);
            }
            if ($handoff) {
                \App\Models\AiInteraction::create(['conversation_id' => $conv->id, 'lead_id' => $lead->id, 'kind' => 'reply', 'provider' => 'openai', 'model' => 'demo', 'status' => 'ok', 'outcome' => 'handoff', 'input' => $msgs[0][2], 'response' => 'A team member will confirm.']);
            }
            $conv->update(['last_inbound_at' => $start->copy()->addMinutes(2 * collect($msgs)->keys()->filter(fn ($k) => $msgs[$k][0] === 'in')->last()), 'last_message_at' => $t->copy()->subMinutes(2),
                'last_message_preview' => mb_substr(end($msgs)[2], 0, 160), 'first_response_at' => $start->copy()->addMinutes(2), 'unread_count' => end($msgs)[0] === 'in' ? 1 : 0]);
            $conv->forceFill(['created_at' => $start])->save();
            $i++;
        }
        $lead = \App\Models\Lead::whereHas('contact', fn ($q) => $q->where('name', 'Usman (demo)'))->first();
        $lead?->update(['product_interest' => '10 marla plot', 'budget_amount' => 9000000, 'budget_currency' => 'PKR', 'ai_summary' => 'Overseas buyer in Dubai interested in a 10 marla plot, budget about PKR 90 lakh.']);
    }

    public function run(OrganizationProvisioner $provisioner, CurrentOrganization $current): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoSeeder must not run in production.');
        }
        $org = $provisioner->create('Demo Realty (synthetic data)', 'Asia/Karachi', 'PKR', 'PK');
        $current->set($org);
        mt_srand(20261012);

        $team = Team::create(['name' => 'Islamabad Sales']);
        $mk = function (string $name, string $email, string $role, ?int $teamId = null) use ($org) {
            $u = new User(['name' => $name, 'email' => $email, 'password' => 'Password123!', 'team_id' => $teamId]);
            $u->organization_id = $org->id;
            $u->save();
            $u->roles()->sync([Role::where('key', $role)->value('id')]);

            return $u;
        };
        $admin = $mk('Admin User', 'admin@demo.test', 'business_admin');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $org->update(['industry' => 'real_estate']);
        app(\App\Services\Ai\AssistantConfig::class)->applyPreset(app(\App\Services\Ai\AssistantConfig::class)->get(), 'real_estate');
        $manager = $mk('Sana Manager', 'manager@demo.test', 'sales_manager', $team->id);
        $team->update(['manager_user_id' => $manager->id]);
        $reps = [$mk('Ali Rep', 'rep1@demo.test', 'sales_rep', $team->id), $mk('Hina Rep', 'rep2@demo.test', 'sales_rep', $team->id)];
        $mk('Omar Marketing', 'marketing@demo.test', 'marketing_manager');
        $mk('Read Only', 'analyst@demo.test', 'analyst');

        $campaigns = [
            Campaign::create(['platform' => 'facebook', 'name' => 'DEMO FB – 2 Bed Apartments CTWA', 'currency' => 'PKR', 'starts_on' => now()->subDays(40)->toDateString()]),
            Campaign::create(['platform' => 'instagram', 'name' => 'DEMO IG – Reels Plot Offer', 'currency' => 'PKR', 'starts_on' => now()->subDays(25)->toDateString()]),
        ];
        foreach ($campaigns as $i => $c) {
            for ($d = 0; $d < 25; $d++) {
                CampaignSpend::create(['campaign_id' => $c->id, 'spend_date' => now('Asia/Karachi')->subDays($d)->toDateString(), 'amount' => 4000 + 1500 * $i + mt_rand(0, 1500), 'currency' => 'PKR']);
            }
        }

        Auth::setUser($admin);
        $leads = app(LeadService::class);
        $followUps = app(FollowUpService::class);
        $statuses = app(LeadStatusService::class);
        $sources = LeadSource::pluck('id', 'key');
        $names = ['Ahmed Khan', 'Fatima Noor', 'Bilal Raza', 'Ayesha Siddiqui', 'Usman Tariq', 'Zainab Ali', 'Hamza Sheikh', 'Maryam Javed', 'Imran Qureshi', 'Sadia Malik', 'Faisal Iqbal', 'Rabia Aslam', 'Kamran Butt', 'Nida Hussain', 'Tariq Mehmood', 'Hira Shah', 'Asad Mirza', 'Sana Yousaf', 'Danish Akhtar', 'Komal Riaz'];
        $outcomes = \App\Models\CallOutcome::pluck('id', 'key');

        foreach ($names as $i => $name) {
            $campaign = $campaigns[$i % 3] ?? null;
            $rep = $reps[$i % 2];
            $lead = $leads->create([
                'contact' => ['name' => $name.' (demo)', 'phone' => '0300'.str_pad((string) (1000000 + $i * 7919), 7, '0', STR_PAD_LEFT), 'city' => ['Islamabad', 'Rawalpindi', 'Lahore'][$i % 3]],
                'lead_source_id' => $sources[$campaign ? ($campaign->platform === 'facebook' ? 'facebook_ads' : 'instagram_ads') : 'referral'],
                'assigned_user_id' => $rep->id,
                'priority' => ['normal', 'high', 'normal', 'urgent', 'low'][$i % 5],
                'product_interest' => ['2 bed apartment', '5 marla plot', '10 marla house'][$i % 3],
                'attribution' => $campaign ? ['campaign_id' => $campaign->id, 'platform' => $campaign->platform] : [],
                'tags' => ['Demo data'],
            ], $admin, 'manual');
            $lead->forceFill(['created_at' => now()->subDays(mt_rand(0, 28))->subHours(mt_rand(0, 20))])->saveQuietly();

            Auth::setUser($rep);
            $stage = $i % 6;
            if ($stage >= 1) {
                $followUps->logCall($lead->fresh(['status', 'contact']), null, ['call_outcome_id' => $outcomes[$stage === 1 ? 'no_answer' : 'answered'], 'notes' => 'Synthetic demo call']);
            }
            if ($stage === 1) {
                $followUps->schedule($lead->fresh(['status', 'contact']), ['scheduled_at' => now()->subHours(mt_rand(2, 30)), 'purpose' => 'Second attempt']);
            }
            if ($stage >= 3) {
                $statuses->transition($lead->fresh(['status', 'contact']), LeadStatus::where('key', 'qualified')->first());
            }
            if ($stage === 4) {
                $statuses->transition($lead->fresh(['status', 'contact']), LeadStatus::where('key', 'won')->first());
            }
            if ($stage === 5) {
                $statuses->transition($lead->fresh(['status', 'contact']), LeadStatus::where('key', 'lost')->first(), 'Budget too low (demo)');
            }
            if ($stage === 2) {
                $followUps->schedule($lead->fresh(['status', 'contact']), ['scheduled_at' => now()->addHours(mt_rand(1, 72)), 'purpose' => 'Send price list']);
            }
            Auth::setUser($admin);
        }

        $this->demoConversations($org, $admin, $reps);
        foreach (\App\Models\Lead::with('status', 'contact')->get() as $l) {
            app(\App\Services\LeadScoring::class)->score($l);
        }

        $this->command?->info('Demo organization created. Sign in with admin@demo.test / Password123! (also manager@, rep1@, rep2@, marketing@, analyst@).');
    }
}
