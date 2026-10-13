<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsInOrganization;
use App\Models\Integration;
use App\Models\WebhookEvent;
use App\Services\Meta\LeadAdsService;
use App\Services\Meta\MetaException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Reads each leadgen id in a Page webhook delivery from the Graph API and creates CRM leads. */
class ProcessMetaLeadgen implements ShouldQueue
{
    use Queueable, RunsInOrganization;

    public int $tries = 5; // Graph API may lag a few seconds behind the webhook

    public array $backoff = [15, 60, 300, 900];

    public function __construct(public int $eventId) {}

    public function handle(LeadAdsService $service): void
    {
        $event = WebhookEvent::find($this->eventId);
        if (! $event || $event->status === 'processed') {
            return;
        }
        $event->increment('attempts');
        $this->withinOrganization($event->organization_id, null, function () use ($event, $service) {
            $i = Integration::findOrFail($event->integration_id);
            $failed = 0;
            foreach ((array) ($event->payload['entry'] ?? []) as $entry) {
                foreach ((array) ($entry['changes'] ?? []) as $change) {
                    if (($change['field'] ?? '') !== 'leadgen' || empty($change['value']['leadgen_id'])) {
                        continue;
                    }
                    try {
                        $service->ingest($i, (string) $change['value']['leadgen_id'], 'webhook');
                    } catch (MetaException $e) {
                        $failed++;
                        $last = $e;
                    }
                }
            }
            if ($failed) {
                $event->forceFill(['status' => 'failed', 'error' => mb_substr($last->getMessage(), 0, 500)])->save();
                if (! $last->isAuthError()) {
                    throw $last; // retry transient failures; auth failures wait for the admin to fix the token
                }

                return;
            }
            $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'error' => null])->save();
        });
    }
}
