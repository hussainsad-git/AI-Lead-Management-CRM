<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsInOrganization;
use App\Models\AudienceSyncJob;
use App\Services\Audiences\MetaAudienceSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncAudienceToMeta implements ShouldQueue, ShouldBeUnique
{
    use Queueable, RunsInOrganization;

    public int $tries = 1; // a replace session is not safely retriable mid-way; the job records failure and the user re-runs

    public int $timeout = 1800;

    public function __construct(public int $jobId, public int $organizationId) {}

    public function uniqueId(): string
    {
        return 'audience-sync:'.$this->jobId;
    }

    public function handle(MetaAudienceSync $sync): void
    {
        $this->withinOrganization($this->organizationId, null, function () use ($sync) {
            $job = AudienceSyncJob::with('segment')->find($this->jobId);
            if ($job && $job->status === 'queued') {
                $sync->run($job);
            }
        });
    }
}
