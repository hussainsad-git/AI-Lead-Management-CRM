<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactIdentity;
use Illuminate\Support\Collection;

/**
 * Duplicate detection. "Strong" matches are exact normalized identifiers (E.164 phone in either
 * phone/WhatsApp field, normalized email, provider external id) and may be used to LINK a new
 * inquiry to an existing contact. "Weak" matches (same name only) are suggestions for human review
 * and are never acted on automatically.
 */
class ContactMatcher
{
    public static function normalizeEmail(?string $email): ?string
    {
        $email = trim(mb_strtolower((string) $email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** @return Collection<int, array{contact: Contact, reasons: array<string>}> */
    public function strongMatches(array $phonesE164, ?string $emailNormalized, array $identities = [], ?int $excludeId = null): Collection
    {
        $phones = array_values(array_unique(array_filter($phonesE164)));
        $found = collect();

        if ($phones) {
            Contact::query()->whereNull('merged_into_id')
                ->where(fn ($q) => $q->whereIn('phone_e164', $phones)->orWhereIn('whatsapp_e164', $phones))
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->limit(20)->get()
                ->each(function (Contact $c) use (&$found, $phones) {
                    $r = [];
                    if (in_array($c->phone_e164, $phones, true)) {
                        $r[] = 'phone';
                    }
                    if (in_array($c->whatsapp_e164, $phones, true)) {
                        $r[] = 'whatsapp';
                    }
                    $found[$c->id] = ['contact' => $c, 'reasons' => $r];
                });
        }

        if ($emailNormalized) {
            Contact::query()->whereNull('merged_into_id')->where('email_normalized', $emailNormalized)
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))->limit(20)->get()
                ->each(function (Contact $c) use (&$found) {
                    $entry = $found[$c->id] ?? ['contact' => $c, 'reasons' => []];
                    $entry['reasons'][] = 'email';
                    $found[$c->id] = $entry;
                });
        }

        foreach ($identities as $provider => $externalId) {
            $identity = ContactIdentity::where('provider', $provider)->where('external_id', $externalId)->first();
            if ($identity && $identity->contact_id !== $excludeId && ($c = Contact::find($identity->contact_id))) {
                $entry = $found[$c->id] ?? ['contact' => $c, 'reasons' => []];
                $entry['reasons'][] = 'identity:'.$provider;
                $found[$c->id] = $entry;
            }
        }

        return $found->values();
    }

    /** All duplicate candidates for an existing contact: strong + weak (name). */
    public function candidatesFor(Contact $contact): Collection
    {
        $strong = $this->strongMatches([$contact->phone_e164, $contact->whatsapp_e164], $contact->email_normalized, [], $contact->id)
            ->map(fn ($m) => $m + ['strength' => 'strong']);

        $weak = collect();
        if ($contact->name && mb_strlen($contact->name) >= 3) {
            $weak = Contact::query()->whereNull('merged_into_id')->where('id', '!=', $contact->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($contact->name)])
                ->whereNotIn('id', $strong->pluck('contact.id'))
                ->limit(10)->get()
                ->map(fn ($c) => ['contact' => $c, 'reasons' => ['name'], 'strength' => 'weak']);
        }

        return $strong->concat($weak)->values();
    }
}
