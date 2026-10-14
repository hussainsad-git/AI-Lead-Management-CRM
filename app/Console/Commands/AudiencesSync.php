<?php

namespace App\Console\Commands;

use App\Jobs\SyncAudienceToMeta;
use App\Models\AudienceSegment;
use App\Models\AudienceSyncJob;
use App\Models\Organization;
use App\Services\Audiences\AudienceService;
use App\Services\Audiences\MetaAudienceSync;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

/** Daily: refresh every segment's membership snapshot; queue a Meta replace for auto-sync segments. */
class AudiencesSync extends Command
{
    protected $signature = 'audiences:sync {--org=}';

    protected $description = 'Refresh audience segment memberships and sync auto-sync segments to Meta';

    public function handle(AudienceService $audiences, MetaAudienceSync $meta, CurrentOrganization $current): int
    {
        Organization::where('is_active', true)->when($this->option('org'), fn ($q, $o) => $q->whereKey((int) $o))->each(function ($org) use ($audiences, $meta, $current) {
            $current->runAs($org, function () use ($audiences, $meta, $org) {
                $connected = (bool) $meta->integration();
                foreach (AudienceSegment::orderBy('id')->get() as $s) {
                    $c = $audiences->refresh($s);
                    $this->line("[{$org->id}] {$s->name}: {$c['members']} members, {$c['eligible']} eligible");
                    if ($s->auto_sync && $connected && ! $s->isExclusion()) {
                        $job = AudienceSyncJob::create(['audience_segment_id' => $s->id, 'kind' => 'meta_replace', 'status' => 'queued']);
                        SyncAudienceToMeta::dispatch($job->id, $org->id);
                    }
                }
            });
        });

        return self::SUCCESS;
    }
}
