<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Organization;
use App\Services\LeadScoring;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

/** Scores decay with time (staleness), so open leads are re-scored periodically. */
class RescoreLeads extends Command
{
    protected $signature = 'leads:rescore';

    protected $description = 'Recalculate lead scores for open leads in every active organization';

    public function handle(LeadScoring $scoring, CurrentOrganization $current): int
    {
        $n = 0;
        Organization::where('is_active', true)->each(function ($org) use ($scoring, $current, &$n) {
            $current->runAs($org, function () use ($scoring, &$n) {
                Lead::whereNull('archived_at')->whereHas('status', fn ($q) => $q->where('category', 'open'))
                    ->with('status', 'contact')->chunkById(500, function ($leads) use ($scoring, &$n) {
                        foreach ($leads as $lead) {
                            $scoring->score($lead);
                            $n++;
                        }
                    });
            });
        });
        $this->info("Scored $n leads.");

        return self::SUCCESS;
    }
}
