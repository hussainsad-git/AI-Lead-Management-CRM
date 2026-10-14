<?php

namespace App\Services\Audiences;

use App\Models\AudienceSegment;
use App\Models\AudienceSyncJob;
use App\Models\Integration;
use App\Services\AuditLogger;
use App\Services\Meta\MetaAdsClient;
use App\Services\Meta\MetaException;
use App\Services\NotificationService;

/**
 * Uploads an eligible segment to a Meta Custom Audience (customer list). Identifiers are SHA-256
 * hashed here before leaving the server; Meta's "replace" session makes every sync a full refresh,
 * so people who opted out since the last sync drop out of the audience.
 */
class MetaAudienceSync
{
    public const SCHEMA = ['PHONE', 'EMAIL', 'COUNTRY'];

    public function __construct(private AudienceService $audiences, private AuditLogger $audit, private NotificationService $notify) {}

    public function integration(): ?Integration
    {
        $i = Integration::where('provider', 'meta_marketing')->first();

        return $i && $i->status === 'connected' && MetaAdsClient::for($i)->configured() ? $i : null;
    }

    public function ensureAudience(AudienceSegment $s): string
    {
        if ($s->meta_audience_id) {
            return $s->meta_audience_id;
        }
        $i = $this->integration() ?? throw new MetaException('Meta Marketing API is not connected.');
        $name = mb_substr('LeadFlow · '.$s->name, 0, 191);
        $id = MetaAdsClient::for($i)->createCustomAudience($name, $s->description ?: 'Customer list managed by LeadFlow CRM');
        $s->forceFill(['meta_audience_id' => $id, 'meta_audience_name' => $name])->save();
        $this->audit->log('audience.meta_created', $s, [], ['meta_audience_id' => $id]);

        return $id;
    }

    /** Runs synchronously inside the queue job. */
    public function run(AudienceSyncJob $job): void
    {
        $s = $job->segment;
        $job->forceFill(['status' => 'running', 'started_at' => now()])->save();
        try {
            if ($s->isExclusion()) {
                throw new MetaException('Exclusion lists are not uploaded to Meta; attach them to an audience segment instead.');
            }
            $i = $this->integration() ?? throw new MetaException('Meta Marketing API is not connected.');
            $client = MetaAdsClient::for($i);
            $audienceId = $this->ensureAudience($s);
            $counts = $this->audiences->refresh($s);
            $job->forceFill(['members' => $counts['members'], 'eligible' => $counts['eligible'], 'skipped' => $counts['skipped']])->save();

            $total = $this->audiences->eligibleContacts($s)->count();
            $session = (string) random_int(1_000_000, 2_147_483_647);
            $batch = (int) config('audiences.meta_batch_size');
            $seq = 0;
            $uploaded = 0;
            $buffer = [];
            $flush = function (bool $last) use (&$buffer, &$seq, &$uploaded, $client, $audienceId, $session, $total) {
                $seq++;
                $client->replaceUsersBatch($audienceId, $session, $seq, $last, max(1, $total), self::SCHEMA, $buffer);
                $uploaded += count($buffer);
                $buffer = [];
            };
            $this->audiences->eligibleContacts($s)->orderBy('id')->chunkById(1000, function ($rows) use (&$buffer, $batch, $flush) {
                foreach ($rows as $m) {
                    if ($row = $this->hashRow($m->contact)) {
                        $buffer[] = $row;
                    }
                    if (count($buffer) >= $batch) {
                        $flush(false);
                    }
                }
            });
            $flush(true); // always send the closing batch (possibly empty) so Meta commits the replace

            $job->forceFill(['status' => 'success', 'uploaded' => $uploaded, 'meta_session_id' => $session, 'finished_at' => now(), 'error' => null])->save();
            $s->forceFill(['last_synced_at' => now()])->saveQuietly();
            $this->audit->log('audience.meta_synced', $s, [], ['job_id' => $job->id, 'uploaded' => $uploaded, 'eligible' => $counts['eligible']]);
            if ($job->created_by) {
                $this->notify->notify($job->created_by, 'audience_sync', 'Audience synced: '.$s->name, "$uploaded people uploaded to Meta (hashed). Match rate is decided by Meta.", '/retargeting', 'audience_sync:'.$job->id);
            }
        } catch (MetaException $e) {
            $msg = $this->explain($e);
            $job->forceFill(['status' => 'failed', 'error' => mb_substr($msg, 0, 500), 'finished_at' => now()])->save();
            foreach (array_unique(array_filter([$job->created_by, ...$this->notify->escalationTargets(null)])) as $uid) {
                $this->notify->notify($uid, 'audience_sync', 'Audience sync failed: '.$s->name, $msg, '/retargeting', 'audience_sync_failed:'.$job->id);
            }
        }
    }

    /** @return array{0:string,1:string,2:string}|null hashed [phone, email, country] */
    public function hashRow($contact): ?array
    {
        if (! $contact) {
            return null;
        }
        $phone = preg_replace('/\D/', '', (string) ($contact->phone_e164 ?: $contact->whatsapp_e164));
        $email = mb_strtolower(trim((string) $contact->email_normalized));
        if ($phone === '' && $email === '') {
            return null;
        }
        $country = mb_strtolower(trim((string) $contact->country_code));

        return [
            $phone !== '' ? hash('sha256', $phone) : '',
            $email !== '' ? hash('sha256', $email) : '',
            strlen($country) === 2 ? hash('sha256', $country) : '',
        ];
    }

    private function explain(MetaException $e): string
    {
        return match (true) {
            in_array($e->graphCode, [2654, 2655], true) => 'The ad account has not accepted the Custom Audience terms (accept them in Ads Manager → Audiences), or the customer-file source is not allowed: '.$e->getMessage(),
            $e->isAuthError() => 'Meta rejected the token or it lacks ads_management: '.$e->getMessage(),
            default => $e->getMessage(),
        };
    }
}
