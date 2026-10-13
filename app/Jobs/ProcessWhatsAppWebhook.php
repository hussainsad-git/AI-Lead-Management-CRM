<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsInOrganization;
use App\Models\Integration;
use App\Models\WebhookEvent;
use App\Services\WhatsApp\InboundProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Queueable, RunsInOrganization;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public int $eventId) {}

    public function handle(InboundProcessor $processor): void
    {
        $event = WebhookEvent::find($this->eventId);
        if (! $event || $event->status === 'processed') {
            return;
        }
        $event->increment('attempts');
        $this->withinOrganization($event->organization_id, null, function () use ($event, $processor) {
            $integration = Integration::findOrFail($event->integration_id);
            try {
                $stats = $processor->process($event, $integration);
                $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'error' => null])->save();
            } catch (Throwable $e) {
                $event->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
                throw $e; // queue retry; processing is idempotent per message id
            }
        });
    }
}
