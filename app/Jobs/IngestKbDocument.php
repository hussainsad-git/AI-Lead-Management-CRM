<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsInOrganization;
use App\Models\KbDocument;
use App\Services\Ai\KnowledgeBase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestKbDocument implements ShouldQueue
{
    use Queueable, RunsInOrganization;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $documentId, public int $organizationId) {}

    public function handle(KnowledgeBase $kb): void
    {
        $this->withinOrganization($this->organizationId, null, function () use ($kb) {
            if ($doc = KbDocument::find($this->documentId)) {
                $kb->ingest($doc);
            }
        });
    }
}
