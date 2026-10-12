<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\FollowUpService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

class MarkMissedFollowUps extends Command
{
    protected $signature = 'followups:mark-missed';

    protected $description = 'Mark pending follow-ups far past due as missed (per organization)';

    public function handle(FollowUpService $service, CurrentOrganization $current): int
    {
        $hours = config('leadflow.followups.missed_after_hours');
        $total = 0;
        Organization::where('is_active', true)->each(function ($org) use ($service, $current, $hours, &$total) {
            $total += $current->runAs($org, fn () => $service->markMissed($hours));
        });
        $this->info("Marked {$total} follow-up(s) as missed.");

        return self::SUCCESS;
    }
}
