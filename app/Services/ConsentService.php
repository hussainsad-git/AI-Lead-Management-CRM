<?php

namespace App\Services;

use App\Models\CallTask;
use App\Models\ConsentRecord;
use App\Models\Contact;
use App\Models\SuppressionRecord;
use Illuminate\Support\Facades\DB;

class ConsentService
{
    public const CHANNELS = ['whatsapp', 'phone', 'sms', 'email', 'ads_audience'];

    public function __construct(private AuditLogger $audit, private ActivityLogger $activity) {}

    public function record(Contact $contact, string $channel, string $status, string $source, ?string $evidence = null): ConsentRecord
    {
        $record = ConsentRecord::create([
            'contact_id' => $contact->id, 'channel' => $channel, 'status' => $status, 'source' => $source,
            'evidence' => $evidence, 'recorded_by' => auth()->id(), 'recorded_at' => now(),
        ]);
        $this->activity->forContact($contact->id, 'consent_'.$status, ucfirst($channel).' consent '.$status, ['channel' => $channel, 'source' => $source]);

        return $record;
    }

    /** Latest status per channel, or 'unknown'. */
    public function summary(Contact $contact): array
    {
        $out = array_fill_keys(self::CHANNELS, 'unknown');
        foreach ($contact->consents()->get() as $r) {
            if ($out[$r->channel] === 'unknown') {
                $out[$r->channel] = $r->status;
            }
        }
        foreach ($contact->suppressions()->whereNull('lifted_at')->get() as $s) {
            foreach ($s->channel === 'all' ? self::CHANNELS : [$s->channel] as $ch) {
                $out[$ch] = 'suppressed';
            }
        }

        return $out;
    }

    /** Opt-out: withdraw consent, add suppression, and for 'all' stop routine follow-up. */
    public function optOut(Contact $contact, string $channel, string $reason, string $source = 'user'): SuppressionRecord
    {
        return DB::transaction(function () use ($contact, $channel, $reason, $source) {
            $existing = SuppressionRecord::where('contact_id', $contact->id)->where('channel', $channel)->whereNull('lifted_at')->first();
            $suppression = $existing ?? SuppressionRecord::create([
                'contact_id' => $contact->id, 'phone_e164' => $contact->phone_e164 ?? $contact->whatsapp_e164,
                'email_normalized' => $contact->email_normalized, 'channel' => $channel, 'reason' => $reason, 'created_by' => auth()->id(),
            ]);
            foreach ($channel === 'all' ? self::CHANNELS : [$channel] as $ch) {
                ConsentRecord::create([
                    'contact_id' => $contact->id, 'channel' => $ch, 'status' => 'withdrawn', 'source' => $source,
                    'evidence' => $reason, 'recorded_by' => auth()->id(), 'recorded_at' => now(),
                ]);
            }
            if ($channel === 'all' || $channel === 'phone') {
                $contact->forceFill(['do_not_contact' => $channel === 'all' ? true : $contact->do_not_contact])->save();
                CallTask::whereIn('lead_id', $contact->leads()->pluck('id'))->where('status', 'pending')
                    ->update(['status' => 'cancelled', 'cancel_reason' => 'Contact opted out', 'updated_at' => now()]);
                $contact->leads()->update(['next_follow_up_at' => null]);
            }
            $this->activity->forContact($contact->id, 'opt_out', 'Opted out ('.$channel.'): '.$reason, ['channel' => $channel]);
            $this->audit->log('contact.opt_out', $contact, [], ['channel' => $channel, 'reason' => $reason]);
            if (! $existing) {
                $engine = app(\App\Services\Automation\AutomationEngine::class);
                $contact->leads()->whereNull('archived_at')->get()->each(fn ($l) => $engine->fire('lead.opted_out', $l, 'suppression:'.$suppression->id.':lead:'.$l->id, ['channel' => $channel]));
            }

            return $suppression;
        });
    }

    public function lift(SuppressionRecord $suppression, string $reason): void
    {
        DB::transaction(function () use ($suppression, $reason) {
            $suppression->forceFill(['lifted_at' => now(), 'lifted_by' => auth()->id()])->save();
            if ($suppression->channel === 'all' && $suppression->contact_id) {
                $still = SuppressionRecord::where('contact_id', $suppression->contact_id)->where('channel', 'all')->whereNull('lifted_at')->exists();
                if (! $still) {
                    Contact::whereKey($suppression->contact_id)->update(['do_not_contact' => false]);
                }
            }
            $this->audit->log('contact.suppression_lifted', $suppression, [], ['reason' => $reason, 'channel' => $suppression->channel]);
        });
    }
}
