<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsInOrganization;
use App\Models\ImportJob;
use App\Services\Import\ImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class StageImport implements ShouldQueue
{
    use Queueable, RunsInOrganization;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $importJobId, public int $organizationId, public ?int $userId) {}

    public function handle(ImportService $imports): void
    {
        $this->withinOrganization($this->organizationId, $this->userId, function () use ($imports) {
            $job = ImportJob::findOrFail($this->importJobId);
            try {
                $imports->stage($job);
            } catch (Throwable $e) {
                report($e);
                $job->update(['status' => 'failed', 'failure_message' => 'Validation failed: '.mb_substr($e->getMessage(), 0, 300)]);
            }
        });
    }
}
