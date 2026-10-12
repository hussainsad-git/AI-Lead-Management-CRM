<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\Activity;
use App\Models\ConsentRecord;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Lead;
use App\Models\SuppressionRecord;
use Illuminate\Support\Facades\DB;

/**
 * Explicit, permission-controlled merge. History, attribution, consent and suppression are
 * carried over; the target's existing values are never overwritten, only blanks filled.
 */
class ContactMergeService
{
    private const FIELDS = ['name', 'company_name', 'email', 'email_normalized', 'phone_raw', 'phone_e164', 'whatsapp_raw', 'whatsapp_e164', 'country_code', 'city', 'preferred_contact_method'];

    public function __construct(private AuditLogger $audit, private ActivityLogger $activity) {}

    public function merge(Contact $target, Contact $source): Contact
    {
        if ($target->id === $source->id) {
            throw new DomainRuleException('Cannot merge a contact into itself.', 'merge_same');
        }
        if ($target->merged_into_id || $source->merged_into_id) {
            throw new DomainRuleException('One of these contacts was already merged.', 'merge_already_merged', 409);
        }

        return DB::transaction(function () use ($target, $source) {
            $snapshot = $source->only(array_merge(['id'], self::FIELDS));
            $filled = [];
            foreach (self::FIELDS as $f) {
                if (($target->{$f} === null || $target->{$f} === '') && $source->{$f} !== null && $source->{$f} !== '') {
                    $target->{$f} = $source->{$f};
                    $filled[] = $f;
                }
            }
            // A second distinct number on the source is kept rather than lost
            if ($source->phone_e164 && $target->phone_e164 !== $source->phone_e164 && ! $target->whatsapp_e164 && $target->phone_e164) {
                $target->whatsapp_raw = $source->phone_raw;
                $target->whatsapp_e164 = $source->phone_e164;
                $filled[] = 'whatsapp(from source phone)';
            }
            $target->do_not_contact = $target->do_not_contact || $source->do_not_contact;
            $target->save();

            $leadIds = Lead::withTrashed()->where('contact_id', $source->id)->pluck('id');
            Lead::withTrashed()->where('contact_id', $source->id)->update(['contact_id' => $target->id]);
            Activity::where('contact_id', $source->id)->update(['contact_id' => $target->id]);
            ConsentRecord::where('contact_id', $source->id)->update(['contact_id' => $target->id]);
            SuppressionRecord::where('contact_id', $source->id)->update(['contact_id' => $target->id]);
            foreach (ContactIdentity::where('contact_id', $source->id)->get() as $identity) {
                $exists = ContactIdentity::where('provider', $identity->provider)->where('external_id', $identity->external_id)->where('contact_id', $target->id)->exists();
                $exists ? $identity->delete() : $identity->update(['contact_id' => $target->id]);
            }

            $source->forceFill(['merged_into_id' => $target->id])->save();
            $source->delete();

            $this->activity->forContact($target->id, 'contact_merged', 'Merged contact #'.$snapshot['id'].' into this record', [
                'source_id' => $snapshot['id'], 'moved_leads' => $leadIds->all(), 'filled_fields' => $filled,
            ]);
            $this->audit->log('contact.merged', $target, ['source' => $snapshot], ['moved_leads' => $leadIds->all(), 'filled_fields' => $filled]);

            return $target->fresh();
        });
    }
}
