<?php

namespace App\Console\Commands;

use App\Models\AiInteraction;
use App\Models\AutomationExecution;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\WebhookEvent;
use App\Services\ActivityLogger;
use App\Services\AuditLogger;
use App\Services\OrganizationSettings;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

/**
 * Nightly housekeeping per organization, driven by its retention settings. Deletes operational
 * exhaust (webhook payloads, notifications, AI transcripts, automation history) and archives old
 * closed leads. Never deletes contacts, leads, consent or audit records — that is erasure, not retention.
 */
class Retention extends Command
{
    protected $signature = 'leadflow:retention {--dry-run : Report what would change without changing it}';

    protected $description = 'Apply each organization\'s data retention settings';

    public function handle(OrganizationSettings $settings, CurrentOrganization $current, AuditLogger $audit, ActivityLogger $activity): int
    {
        $dry = (bool) $this->option('dry-run');
        Organization::where('is_active', true)->each(function ($org) use ($settings, $current, $audit, $activity, $dry) {
            $current->runAs($org, function () use ($org, $settings, $audit, $activity, $dry) {
                $s = $settings->all();
                $n = [];
                $cut = fn ($key) => now()->subDays(max(1, (int) $s[$key]));

                $wh = WebhookEvent::whereIn('status', ['processed', 'ignored'])->where('created_at', '<', $cut('retention_purge_webhooks_days'));
                $n['webhook_events'] = $dry ? $wh->count() : $wh->delete();

                $nt = Notification::where('created_at', '<', $cut('retention_purge_notifications_days'));
                $n['notifications'] = $dry ? $nt->count() : $nt->delete();

                $ai = AiInteraction::where('created_at', '<', $cut('retention_purge_ai_days'))->where(fn ($q) => $q->whereNotNull('input')->orWhereNotNull('response'));
                $n['ai_transcripts'] = $dry ? $ai->count() : $ai->update(['input' => null, 'response' => null, 'corrected_text' => null]);

                $ae = AutomationExecution::where('created_at', '<', $cut('retention_purge_automation_days'));
                $n['automation_executions'] = $dry ? $ae->count() : $ae->delete();

                $n['leads_archived'] = 0;
                if (! empty($s['retention_archive_after_days'])) {
                    $leads = Lead::whereNull('archived_at')->whereNotNull('closed_at')->where('closed_at', '<', $cut('retention_archive_after_days'));
                    if ($dry) {
                        $n['leads_archived'] = $leads->count();
                    } else {
                        $leads->orderBy('id')->chunkById(500, function ($chunk) use (&$n, $activity) {
                            foreach ($chunk as $lead) {
                                $lead->forceFill(['archived_at' => now()])->saveQuietly();
                                $activity->forLead($lead, 'archived', 'Retention policy', ['automatic' => true]);
                                $n['leads_archived']++;
                            }
                        });
                    }
                }
                if (! $dry && array_sum($n) > 0) {
                    $audit->log('retention.applied', $org, [], $n);
                }
                $this->line("[{$org->id}] ".($dry ? 'would apply ' : 'applied ').json_encode($n));
            });
        });

        return self::SUCCESS;
    }
}
