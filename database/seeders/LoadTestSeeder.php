<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadAttribution;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Synthetic volume for performance checks only (php artisan db:seed --class=LoadTestSeeder).
 * Creates a separate "Load Test" business with LOADTEST_LEADS leads (default 25,000). Never run on production data.
 */
class LoadTestSeeder extends Seeder
{
    public function run(OrganizationProvisioner $provisioner, CurrentOrganization $current): void
    {
        $n = (int) (env('LOADTEST_LEADS', 25000));
        $org = Organization::where('slug', 'load-test')->first() ?? $provisioner->create('Load Test (synthetic)', 'Asia/Karachi', 'PKR', 'PK');
        $current->runAs($org, function () use ($org, $n) {
            if (! User::where('email', 'load@test.local')->exists()) {
                $u = new User(['name' => 'Load Admin', 'email' => 'load@test.local', 'password' => 'Password123!']);
                $u->organization_id = $org->id;
                $u->save();
                $u->roles()->sync([Role::where('key', 'business_admin')->value('id')]);
                foreach (range(1, 8) as $i) {
                    $r = new User(['name' => "Rep $i", 'email' => "rep$i@load.local", 'password' => 'Password123!']);
                    $r->organization_id = $org->id;
                    $r->save();
                    $r->roles()->sync([Role::where('key', 'sales_rep')->value('id')]);
                }
            }
            $reps = User::where('organization_id', $org->id)->whereHas('roles', fn ($q) => $q->where('key', 'sales_rep'))->pluck('id')->all();
            $statuses = LeadStatus::where('is_active', true)->get();
            $sources = LeadSource::pluck('id')->all();
            $existing = Lead::count();
            $now = now();
            for ($done = $existing; $done < $n; $done += 1000) {
                $batch = min(1000, $n - $done);
                $contacts = [];
                for ($i = 0; $i < $batch; $i++) {
                    $num = 3000000000 + $done + $i;
                    $contacts[] = ['organization_id' => $org->id, 'type' => 'individual', 'name' => 'Person '.($done + $i), 'phone_raw' => '0'.$num, 'phone_e164' => '+92'.$num,
                        'country_code' => 'PK', 'city' => ['Lahore', 'Karachi', 'Islamabad', 'Multan'][$i % 4], 'created_at' => $now, 'updated_at' => $now];
                }
                DB::table('contacts')->insert($contacts);
                $ids = DB::table('contacts')->where('organization_id', $org->id)->orderByDesc('id')->limit($batch)->pluck('id')->reverse()->values();
                $leads = [];
                foreach ($ids as $i => $cid) {
                    $st = $statuses[($done + $i) % $statuses->count()];
                    $created = $now->copy()->subMinutes(random_int(0, 60 * 24 * 120));
                    $contacted = $st->semantic->value === 'new' ? null : $created->copy()->addHours(random_int(1, 48));
                    $leads[] = ['organization_id' => $org->id, 'contact_id' => $cid, 'lead_status_id' => $st->id, 'lead_source_id' => $sources[$i % count($sources)],
                        'assigned_user_id' => $reps[$i % count($reps)], 'priority' => ['low', 'normal', 'high', 'urgent'][$i % 4], 'score' => $i % 100, 'score_label' => ['cold', 'warm', 'hot'][$i % 3],
                        'contact_attempts' => $i % 5, 'unanswered_attempts' => $i % 3, 'first_contacted_at' => $contacted, 'last_contacted_at' => $contacted,
                        'first_responded_at' => $st->semantic->rank() >= 5 ? $contacted : null, 'qualified_at' => in_array($st->semantic->value, ['qualified', 'appointment', 'proposal', 'won']) ? $contacted : null,
                        'converted_at' => $st->category === 'won' ? $contacted : null, 'closed_at' => $st->category !== 'open' ? $contacted : null,
                        'last_activity_at' => $contacted ?? $created, 'created_at' => $created, 'updated_at' => $created];
                }
                DB::table('leads')->insert($leads);
                $leadIds = DB::table('leads')->where('organization_id', $org->id)->orderByDesc('id')->limit($batch)->pluck('id')->reverse()->values();
                $attr = [];
                foreach ($leadIds as $i => $lid) {
                    $attr[] = ['organization_id' => $org->id, 'lead_id' => $lid, 'touch' => 'first', 'platform' => ['facebook', 'instagram', 'whatsapp'][$i % 3], 'source' => 'ad',
                        'campaign_name_snapshot' => 'Campaign '.($i % 12), 'completeness' => 'partial', 'captured_via' => 'manual', 'captured_at' => $now, 'created_at' => $now, 'updated_at' => $now];
                }
                DB::table('lead_attributions')->insert($attr);
                $this->command?->getOutput()->write("\r".($done + $batch)." / $n");
            }
            $this->command?->info("\nLoad-test business ready: load@test.local / Password123!");
        });
    }
}
