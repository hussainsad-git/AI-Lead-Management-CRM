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

        $this->command?->info('Demo organization created. Sign in with admin@demo.test / Password123! (also manager@, rep1@, rep2@, marketing@, analyst@).');
    }
}
