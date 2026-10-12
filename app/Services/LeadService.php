<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public function __construct(
        private PhoneNormalizer $phones,
        private ContactMatcher $matcher,
        private LeadStatusService $statuses,
        private AttributionService $attribution,
        private ActivityLogger $activity,
        private AssignmentService $assignment,
        private ConsentService $consent,
        private OrganizationSettings $settings,
        private LeadVisibility $visibility,
        private CurrentOrganization $org,
    ) {}

    /**
     * Normalizes and validates contact identifiers. Returns contact attributes.
     *
     * @throws ValidationException
     */
    public function contactAttributes(array $c, bool $requireIdentifier = true): array
    {
        $region = strtoupper($c['phone_region'] ?? $this->org->require()->default_country);
        $errors = [];
        $phone = $this->phones->normalize($c['phone'] ?? null, $region);
        $wa = $this->phones->normalize($c['whatsapp'] ?? null, $region);
        if ($phone['raw'] !== '' && ! $phone['valid']) {
            $errors['contact.phone'] = 'Phone number is not valid for the selected country.';
        }
        if ($wa['raw'] !== '' && ! $wa['valid']) {
            $errors['contact.whatsapp'] = 'WhatsApp number is not valid for the selected country.';
        }
        $email = isset($c['email']) && trim((string) $c['email']) !== '' ? trim($c['email']) : null;
        $emailNorm = ContactMatcher::normalizeEmail($email);
        if ($email && ! $emailNorm) {
            $errors['contact.email'] = 'Email address is not valid.';
        }
        if ($requireIdentifier && ! $errors) {
            $mode = $this->settings->get('required_identifier');
            $has = $phone['e164'] || $wa['e164'] || ($mode === 'any' && $emailNorm);
            if (! $has) {
                $errors['contact.phone'] = $mode === 'any'
                    ? 'Provide at least a phone, WhatsApp number or email.'
                    : 'Provide at least a valid phone or WhatsApp number.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return array_filter([
            'type' => $c['type'] ?? null,
            'name' => isset($c['name']) ? trim($c['name']) ?: null : null,
            'company_name' => isset($c['company_name']) ? trim($c['company_name']) ?: null : null,
            'email' => $email, 'email_normalized' => $emailNorm,
            'phone_raw' => $phone['raw'] ?: null, 'phone_e164' => $phone['e164'],
            'whatsapp_raw' => $wa['raw'] ?: null, 'whatsapp_e164' => $wa['e164'],
            'country_code' => $phone['region'] ?? $wa['region'] ?? ($c['country_code'] ?? null),
            'city' => $c['city'] ?? null,
            'preferred_contact_method' => $c['preferred_contact_method'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Creates a lead, linking to an existing contact on an exact identifier match
     * (or an explicitly chosen contact) and otherwise creating a new contact.
     */
    public function create(array $data, User $actor, string $via = 'manual'): Lead
    {
        return DB::transaction(function () use ($data, $actor, $via) {
            $contact = null;
            if (! empty($data['contact_id'])) {
                $contact = Contact::whereNull('merged_into_id')->findOrFail($data['contact_id']);
            }
            $attrs = $contact ? null : $this->contactAttributes($data['contact'] ?? []);

            if (! $contact && ($data['contact_strategy'] ?? 'link') === 'link') {
                $match = $this->matcher->strongMatches([$attrs['phone_e164'] ?? null, $attrs['whatsapp_e164'] ?? null], $attrs['email_normalized'] ?? null)->first();
                $contact = $match['contact'] ?? null;
                if ($contact) {
                    $this->fillBlanks($contact, $attrs);
                }
            }
            if (! $contact) {
                $contact = Contact::create($attrs + ['created_by' => $actor->id]);
            }

            $allowDuplicate = ($data['allow_duplicate_open_lead'] ?? false) || $this->settings->get('allow_duplicate_open_leads');
            if (! $allowDuplicate) {
                $open = Lead::where('contact_id', $contact->id)->whereNull('archived_at')
                    ->whereHas('status', fn ($q) => $q->where('category', 'open'))->first();
                if ($open) {
                    $visible = $this->visibility->canSee($actor, $open);
                    throw new DomainRuleException(
                        $visible ? "This contact already has an open lead (#{$open->id})." : 'This contact already has an open lead assigned to another user.',
                        'duplicate_open_lead', 409,
                        ['existing_lead_id' => $visible ? $open->id : null, 'contact_id' => $visible ? $contact->id : null]
                    );
                }
            }

            $status = ! empty($data['lead_status_id']) ? LeadStatus::findOrFail($data['lead_status_id']) : $this->statuses->defaultStatus();
            if ($status->requires_reason !== 'none' || $status->category !== 'open') {
                throw new DomainRuleException('New leads must start in an open pipeline stage.', 'invalid_initial_status');
            }

            $lead = Lead::create([
                'contact_id' => $contact->id,
                'lead_source_id' => $data['lead_source_id'] ?? null,
                'lead_status_id' => $status->id,
                'priority' => $data['priority'] ?? 'normal',
                'product_interest' => $data['product_interest'] ?? null,
                'budget_amount' => $data['budget_amount'] ?? null,
                'budget_currency' => isset($data['budget_amount']) ? ($data['budget_currency'] ?? $this->org->require()->currency) : null,
                'location' => $data['location'] ?? null,
                'source_notes' => $data['source_notes'] ?? null,
                'external_lead_id' => $data['external_lead_id'] ?? null,
                'created_by' => $actor->id,
                'last_activity_at' => now(),
            ]);
            if (isset($data['created_at'])) { // imports may carry the original inquiry date
                $lead->forceFill(['created_at' => $data['created_at']])->saveQuietly();
            }
            $lead->setRelation('status', $status);
            $lead->setRelation('contact', $contact);

            $this->activity->forLead($lead, 'lead_created', $data['note'] ?? null, ['via' => $via]);

            $this->attribution->record($lead, $data['attribution'] ?? [], $via === 'import' ? 'import' : 'manual', $via === 'import');

            if (! empty($data['tags'])) {
                $this->syncTags($lead, $data['tags']);
            }

            $assignee = $data['assigned_user_id'] ?? null;
            // A rep who can only see their own leads keeps what they create.
            if (! $assignee && $this->visibility->assigneeScope($actor) === [$actor->id]) {
                $assignee = $actor->id;
            }
            if ($assignee) {
                $this->assignment->assign($lead, (int) $assignee, 'Initial assignment');
            }

            foreach ($data['consents'] ?? [] as $channel => $granted) {
                if ($granted) {
                    $this->consent->record($contact, $channel, 'granted', $via === 'import' ? 'import' : 'manual_entry', $data['consent_evidence'] ?? null);
                }
            }

            return $lead->fresh(['contact', 'status', 'source', 'assignee', 'tags', 'firstTouch']);
        });
    }

    public function update(Lead $lead, array $data): Lead
    {
        return DB::transaction(function () use ($lead, $data) {
            $leadFields = array_intersect_key($data, array_flip([
                'lead_source_id', 'priority', 'product_interest', 'budget_amount', 'budget_currency', 'location',
                'source_notes', 'deal_value', 'deal_currency',
            ]));
            if (array_key_exists('budget_amount', $leadFields) && $leadFields['budget_amount'] !== null && empty($leadFields['budget_currency'])) {
                $leadFields['budget_currency'] = $lead->budget_currency ?? $this->org->require()->currency;
            }
            if (array_key_exists('deal_value', $leadFields) && $leadFields['deal_value'] !== null && empty($leadFields['deal_currency'])) {
                $leadFields['deal_currency'] = $lead->deal_currency ?? $this->org->require()->currency;
            }
            $lead->fill($leadFields);
            $changes = [];
            foreach ($lead->getDirty() as $k => $v) {
                $changes[$k] = ['old' => $lead->getOriginal($k), 'new' => $v];
            }
            $lead->save();

            if (isset($data['contact']) && is_array($data['contact'])) {
                $contact = $lead->contact;
                $attrs = $this->contactAttributes($data['contact'] + [
                    'phone' => $contact->phone_raw, 'whatsapp' => $contact->whatsapp_raw, 'email' => $contact->email,
                ]);
                // explicit empty strings clear optional fields
                foreach (['name', 'company_name', 'city', 'email'] as $f) {
                    if (array_key_exists($f, $data['contact']) && ($data['contact'][$f] === null || $data['contact'][$f] === '')) {
                        $attrs[$f] = null;
                        if ($f === 'email') {
                            $attrs['email_normalized'] = null;
                        }
                    }
                }
                $contact->fill($attrs);
                foreach ($contact->getDirty() as $k => $v) {
                    if (! in_array($k, ['email_normalized'], true)) {
                        $changes['contact.'.$k] = ['old' => $contact->getOriginal($k), 'new' => $v];
                    }
                }
                $contact->save();
            }

            if (array_key_exists('tags', $data)) {
                $before = $lead->tags()->pluck('name')->all();
                $this->syncTags($lead, (array) $data['tags']);
                $after = $lead->tags()->pluck('name')->all();
                if ($before != $after) {
                    $changes['tags'] = ['old' => $before, 'new' => $after];
                }
            }

            if ($changes) {
                $this->activity->forLead($lead, 'lead_updated', null, ['changes' => $changes]);
            }

            return $lead->fresh(['contact', 'status', 'source', 'assignee', 'tags', 'firstTouch']);
        });
    }

    public function addNote(Lead $lead, string $body): \App\Models\Activity
    {
        return $this->activity->forLead($lead, 'note', $body);
    }

    public function archive(Lead $lead, string $reason): void
    {
        $lead->forceFill(['archived_at' => now()])->save();
        $lead->callTasks()->where('status', 'pending')->update(['status' => 'cancelled', 'cancel_reason' => 'Lead archived', 'updated_at' => now()]);
        $this->activity->forLead($lead, 'archived', $reason);
    }

    public function syncTags(Lead $lead, array $names): void
    {
        $ids = [];
        foreach (array_unique(array_filter(array_map(fn ($n) => mb_substr(trim((string) $n), 0, 60), $names))) as $name) {
            $ids[] = Tag::firstOrCreate(['name' => $name])->id;
        }
        $lead->tags()->sync($ids);
    }

    /** Fill only empty contact fields from new data; never overwrite existing values. */
    public function fillBlanks(Contact $contact, array $attrs): void
    {
        foreach ($attrs as $k => $v) {
            if ($v !== null && ($contact->{$k} === null || $contact->{$k} === '')) {
                $contact->{$k} = $v;
            }
        }
        if ($contact->isDirty()) {
            $contact->save();
        }
    }
}
