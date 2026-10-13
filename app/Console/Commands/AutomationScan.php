<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Automation\AutomationEngine;
use App\Services\Reminders;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

/** Every 5 minutes: timed automation rules (SLA, overdue, inactivity) + upcoming-callback/appointment reminders. */
class AutomationScan extends Command
{
    protected $signature = 'automation:scan';

    protected $description = 'Run time-based automation rules and send due reminders';

    public function handle(AutomationEngine $engine, Reminders $reminders, CurrentOrganization $current): int
    {
        $runs = 0;
        $sent = 0;
        Organization::where('is_active', true)->each(function ($org) use ($engine, $reminders, $current, &$runs, &$sent) {
            $current->runAs($org, function () use ($engine, $reminders, &$runs, &$sent) {
                $runs += $engine->scan();
                $sent += $reminders->send();
            });
        });
        $this->info("Automation runs: $runs · reminders: $sent");

        return self::SUCCESS;
    }
}
