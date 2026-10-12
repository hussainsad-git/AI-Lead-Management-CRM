<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsInOrganization;
use App\Models\ImportJob;
use App\Services\Import\ImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CommitImport implements ShouldQueue
{
    use Queueable, RunsInOrganization;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $importJobId, public int $organizationId, public ?int $userId) {}

    public function handle(ImportService $imports): void
    {
        $this->withinOrganization($this->organizationId, $this->userId, function ($user) use ($imports) {
            $job = ImportJob::findOrFail($this->importJobId);
            try {
                $imports->process($job, $user ?? $job->user);
            } catch (Throwable $e) {
                report($e);
                // Rows already committed keep their result; re-running continues with the rest.
                $job->update(['status' => 'failed', 'failure_message' => 'Import stopped: '.mb_substr($e->getMessage(), 0, 300)]);
            }
        });
    }
}
