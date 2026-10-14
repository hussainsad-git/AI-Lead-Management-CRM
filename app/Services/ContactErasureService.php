<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\Activity;
use App\Models\AiInteraction;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\SuppressionRecord;
use Illuminate\Support\Facades\DB;

/**
 * Legal erasure of a person's data. Irreversible. Overwrites every field that identifies the person
 * (contact, identities, message bodies, notes, AI transcripts, lead free text) while keeping the record
 * skeleton so counts, attribution and audit history stay consistent. Opt-out protection survives:
 * suppression rows keep their normalized identifiers so an erased person is never contacted again.
 */
class ContactErasureService
{
    public function __construct(private AuditLogger $audit, private ActivityLogger $activity) {}

    public function erase(Contact $contact, string $reason): array
    {
        if ($contact->erased_at) {
            throw new DomainRuleException('This contact was already erased.', 'already_erased', 409);
        }
        if ($contact->merged_into_id) {
            throw new DomainRuleException('Erase the surviving contact of the merge instead.', 'merged_contact', 409);
        }

        return DB::transaction(function () use ($contact, $reason) {
            $leadIds = Lead::withTrashed()->where('contact_id', $contact->id)->pluck('id');
            $convIds = Conversation::where('contact_id', $contact->id)->pluck('id');
            $n = ['leads' => $leadIds->count(), 'conversations' => $convIds->count()];

            // Keep the opt-out effective even after the identifiers are gone from the contact.
            $hadSuppression = SuppressionRecord::where('contact_id', $contact->id)->whereNull('lifted_at')->exists();
            if (! $hadSuppression && ($contact->phone_e164 || $contact->whatsapp_e164 || $contact->email_normalized)) {
                SuppressionRecord::create(['contact_id' => $contact->id, 'phone_e164' => $contact->phone_e164 ?? $contact->whatsapp_e164,
                    'email_normalized' => $contact->email_normalized, 'channel' => 'all', 'reason' => 'Erased: '.$reason, 'created_by' => auth()->id()]);
            }

            $n['messages'] = Message::whereIn('conversation_id', $convIds)->update(['body' => null, 'media' => null, 'meta' => null, 'updated_at' => now()]);
            $n['ai'] = AiInteraction::whereIn('conversation_id', $convIds)->update(['input' => null, 'response' => null, 'corrected_text' => null, 'updated_at' => now()]);
            foreach (Conversation::whereIn('id', $convIds)->get() as $i => $conv) { // unique (org, channel, external_id)
                $conv->forceFill(['external_id' => 'erased:'.$contact->id.':'.$i, 'last_message_preview' => null, 'status' => 'closed'])->saveQuietly();
            }
            $n['notes'] = Activity::where(fn ($q) => $q->whereIn('lead_id', $leadIds)->orWhere('contact_id', $contact->id))->whereNotNull('body')->update(['body' => '[erased]', 'updated_at' => now()]);
            Lead::withTrashed()->whereIn('id', $leadIds)->update(['source_notes' => null, 'ai_summary' => null, 'location' => null, 'updated_at' => now()]);
            ContactIdentity::where('contact_id', $contact->id)->delete();

            $contact->forceFill([
                'name' => null, 'company_name' => null, 'email' => null, 'email_normalized' => null,
                'phone_raw' => null, 'phone_e164' => null, 'whatsapp_raw' => null, 'whatsapp_e164' => null, 'city' => null,
                'do_not_contact' => true, 'erased_at' => now(),
            ])->save();

            $this->activity->forContact($contact->id, 'erased', 'Personal data erased: '.$reason);
            $this->audit->log('contact.erased', $contact, [], ['reason' => $reason] + $n);

            return $n;
        });
    }
}
