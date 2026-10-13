<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsInOrganization;
use App\Models\Lead;
use App\Services\Automation\AutomationEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunAutomation implements ShouldQueue
{
    use Queueable, RunsInOrganization;

    public int $tries = 3; // safe: executions are de-duplicated per (rule, event)

    public array $backoff = [30, 120];

    public function __construct(public string $trigger, public int $leadId, public int $organizationId, public string $eventId, public array $ctx = [], public int $depth = 0) {}

    public function handle(AutomationEngine $engine): void
    {
        $this->withinOrganization($this->organizationId, null, function () use ($engine) {
            if ($lead = Lead::find($this->leadId)) {
                $engine->run($this->trigger, $lead, $this->eventId, $this->ctx, $this->depth);
            }
        });
    }
}
