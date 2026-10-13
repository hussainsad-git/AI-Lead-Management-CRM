<?php

namespace App\Services\Meta;

use App\Exceptions\DomainRuleException;
use App\Models\Ad;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\LeadFormSubmission;
use App\Models\LeadSource;
use App\Services\ActivityLogger;
use App\Services\AssignmentRouter;
use App\Services\AttributionService;
use App\Services\ContactMatcher;
use App\Services\LeadService;
use App\Services\NotificationService;
use App\Services\PhoneNormalizer;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Meta Lead Ads (Instant Forms). A leadgen id arrives by webhook or backfill; the lead is read from
 * the Graph API with the Page token and turned into a CRM lead with complete attribution.
 * Exactly-once per leadgen id (lead_form_submissions unique key). Only answers the person actually
 * submitted are stored; standard fields map to the contact, custom questions go to source notes.
 */
class LeadAdsService
{
    private const STANDARD = ['full_name', 'first_name', 'last_name', 'email', 'phone_number', 'city', 'company_name', 'work_email', 'work_phone_number'];

    public function __construct(
        private LeadService $leads, private AttributionService $attribution, private ContactMatcher $matcher,
        private PhoneNormalizer $phones, private ActivityLogger $activity, private CurrentOrganization $org,
        private NotificationService $notify, private AssignmentRouter $router,
    ) {}

    /** @return string outcome */
    public function ingest(Integration $i, string $leadgenId, string $via, ?array $prefetched = null): string
    {
        $leadgenId = preg_replace('/\D/', '', $leadgenId);
        try {
            $sub = LeadFormSubmission::firstOrCreate(['external_lead_id' => $leadgenId], ['received_via' => $via, 'outcome' => 'pending']);
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }
        if (in_array($sub->outcome, ['created', 'existing_lead', 'no_contact'], true)) {
            return 'duplicate';
        }
        $sub->increment('attempts');

        try {
            $data = $prefetched ?? MetaAdsClient::for($i)->lead($leadgenId);
            $outcome = $this->toLead($i, $data, $sub);
            $i->forceFill(['last_webhook_at' => $via === 'webhook' ? now() : $i->last_webhook_at])->saveQuietly();

            return $outcome;
        } catch (MetaException $e) {
            $sub->update(['outcome' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            if ($e->isAuthError()) {
                $i->markError($e->getMessage());
                foreach ($this->notify->escalationTargets(null) as $uid) {
                    $this->notify->notify($uid, 'integration_error', 'Meta Lead Ads: cannot read leads', $e->getMessage(), '/integrations', 'meta_auth:'.now()->format('YmdH'));
                }
            }
            throw $e;
        }
    }

    private function toLead(Integration $i, array $d, LeadFormSubmission $sub): string
    {
        $answers = [];
        foreach ((array) ($d['field_data'] ?? []) as $f) {
            $answers[(string) ($f['name'] ?? '')] = trim(implode(', ', array_map('strval', (array) ($f['values'] ?? []))));
        }
        $answers = array_filter($answers, fn ($v, $k) => $k !== '' && $v !== '', ARRAY_FILTER_USE_BOTH);

        $form = ! empty($d['form_id']) ? LeadForm::firstOrCreate(['external_id' => (string) $d['form_id']], ['name' => 'Form '.$d['form_id'], 'page_id' => $i->cfg('page_id')]) : null;
        $submitted = isset($d['created_time']) ? \Carbon\CarbonImmutable::parse($d['created_time'])->utc() : now();
        $sub->forceFill(['lead_form_id' => $form?->id, 'submitted_at' => $submitted])->save();

        $region = $this->org->require()->default_country ?: 'PK';
        $phoneRaw = $answers['phone_number'] ?? $answers['work_phone_number'] ?? null;
        $phone = $this->phones->normalize($phoneRaw, $region);
        $email = $answers['email'] ?? $answers['work_email'] ?? null;
        $emailNorm = ContactMatcher::normalizeEmail($email);
        $name = $answers['full_name'] ?? trim(($answers['first_name'] ?? '').' '.($answers['last_name'] ?? '')) ?: null;
        if (! $phone['e164'] && ! $emailNorm) {
            $sub->update(['outcome' => 'no_contact', 'error' => 'Submission had no valid phone or email.']);

            return 'no_contact';
        }

        $custom = array_diff_key($answers, array_flip(self::STANDARD));
        $notes = collect($custom)->map(fn ($v, $k) => str_replace('_', ' ', ucfirst($k)).': '.$v)->values();
        foreach ((array) ($d['custom_disclaimer_responses'] ?? []) as $c) {
            $notes->push('Consent checkbox "'.($c['checkbox_key'] ?? '?').'": '.(! empty($c['is_checked']) ? 'checked' : 'not checked'));
        }
        if ($phoneRaw && ! $phone['valid']) {
            $notes->push('Phone as submitted (not valid for '.$region.'): '.$phoneRaw);
        }
        $platform = match ($d['platform'] ?? null) { 'ig' => 'instagram', 'fb' => 'facebook', default => 'meta' };
        $attr = $this->attributionFor($i, $d, $platform);

        // Same person already in the pipeline → attach the new submission to their open lead.
        $existing = $this->matcher->strongMatches([$phone['e164']], $emailNorm)->first();
        $open = $existing ? Lead::where('contact_id', $existing['contact']->id)->whereNull('archived_at')
            ->whereHas('status', fn ($q) => $q->where('category', 'open'))->latest('id')->first() : null;
        if ($open) {
            $this->attribution->record($open, $attr, 'meta_leadgen');
            $this->activity->forLead($open, 'form_submitted', $notes->implode("\n") ?: null, ['form' => $form?->name, 'leadgen_id' => $sub->external_lead_id, 'platform' => $platform]);
            $open->forceFill(['last_activity_at' => now()])->saveQuietly();
            $this->notify->notify($open->assigned_user_id, 'lead_assigned', 'Repeat form submission: '.($name ?: $open->contact->displayName()), $form?->name, '/leads/'.$open->id, 'leadgen:'.$sub->external_lead_id);
            $sub->update(['outcome' => 'existing_lead', 'lead_id' => $open->id, 'error' => null]);

            return 'existing_lead';
        }

        $assignee = $this->router->next();
        try {
            $lead = $this->leads->create([
                'contact' => array_filter(['name' => $name, 'phone' => $phone['valid'] ? $phone['e164'] : null, 'email' => $emailNorm ? $email : null,
                    'city' => $answers['city'] ?? null, 'company_name' => $answers['company_name'] ?? null, 'type' => isset($answers['company_name']) ? 'business' : null]),
                'lead_source_id' => LeadSource::where('key', 'meta_instant_form')->value('id'),
                'external_lead_id' => $sub->external_lead_id,
                'source_notes' => $notes ? mb_substr(($form ? 'Form: '.$form->name."\n" : '').$notes->implode("\n"), 0, 2000) : ($form ? 'Form: '.$form->name : null),
                'attribution' => $attr, 'attribution_via' => 'meta_leadgen',
                'assigned_user_id' => $assignee?->id,
                'note' => 'Received from Meta Instant Form'.($form ? ' "'.$form->name.'"' : ''),
            ], null, 'meta_leadgen');
        } catch (ValidationException|DomainRuleException $e) {
            $msg = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $sub->update(['outcome' => 'failed', 'error' => mb_substr((string) $msg, 0, 500)]);

            return 'failed';
        } catch (UniqueConstraintViolationException) { // created concurrently by the other path
            $sub->update(['outcome' => 'created', 'lead_id' => Lead::where('external_lead_id', $sub->external_lead_id)->value('id')]);

            return 'duplicate';
        }
        if ($lead->created_at->gt($submitted)) {
            $lead->forceFill(['created_at' => $submitted])->saveQuietly(); // inquiry time, for SLA and cohort reporting
        }
        $sub->update(['outcome' => 'created', 'lead_id' => $lead->id, 'error' => null]);
        $form?->forceFill(['leads_received' => $form->leads_received + 1, 'last_lead_at' => $submitted])->saveQuietly();
        if ($assignee) {
            $this->notify->notify($assignee->id, 'lead_assigned', 'New Instant Form lead: '.$lead->contact->displayName(), $form?->name, '/leads/'.$lead->id, 'assigned:'.$lead->id.':'.$assignee->id);
        }

        return 'created';
    }

    /** Ensures the campaign/ad set/ad exist (ids + names from the lead) so attribution is complete before the next sync. */
    private function attributionFor(Integration $i, array $d, string $platform): array
    {
        if (empty($d['campaign_id']) && empty($d['ad_id'])) {
            return ['platform' => $platform, 'source' => 'meta_instant_form', 'medium' => ! empty($d['is_organic']) ? 'organic' : 'paid_social'];
        }
        $currency = $i->cfg('account_currency') ?: $this->org->require()->currency;
        $campaign = null;
        if (! empty($d['campaign_id'])) {
            $campaign = Campaign::where('platform', 'meta')->where('external_id', $d['campaign_id'])->first()
                ?? app(MetaSyncService::class)->upsertCampaign(['id' => $d['campaign_id'], 'name' => $d['campaign_name'] ?? $d['campaign_id'], 'effective_status' => 'ACTIVE'], MetaAdsClient::for($i)->accountId(), $currency);
            if (! empty($d['adset_id'])) {
                $set = AdSet::firstOrCreate(['campaign_id' => $campaign->id, 'external_id' => $d['adset_id']], ['name' => mb_substr($d['adset_name'] ?? $d['adset_id'], 0, 191)]);
            }
            if (! empty($d['ad_id'])) {
                Ad::firstOrCreate(['campaign_id' => $campaign->id, 'external_id' => $d['ad_id']], ['name' => mb_substr($d['ad_name'] ?? $d['ad_id'], 0, 191), 'ad_set_id' => $set->id ?? null]);
            }
        }

        return [
            'platform' => $platform, 'source' => 'meta_instant_form', 'medium' => 'paid_social', 'campaign_id' => $campaign?->id,
            'external_campaign_id' => $d['campaign_id'] ?? null, 'campaign_name' => $d['campaign_name'] ?? null,
            'external_ad_set_id' => $d['adset_id'] ?? null, 'ad_set_name' => $d['adset_name'] ?? null,
            'external_ad_id' => $d['ad_id'] ?? null, 'ad_name' => $d['ad_name'] ?? null,
            'referral' => ['form_id' => $d['form_id'] ?? null, 'leadgen_id' => $d['id'] ?? null],
        ];
    }

    /** Pull forms, then every lead created since $since (unix) for each form. Missed webhooks are recovered here. */
    public function backfill(Integration $i, int $since): array
    {
        $client = MetaAdsClient::for($i);
        $stats = ['forms' => 0, 'seen' => 0, 'created' => 0, 'existing_lead' => 0, 'duplicate' => 0, 'failed' => 0, 'no_contact' => 0];
        foreach ($client->leadForms() as $f) {
            $form = LeadForm::updateOrCreate(['external_id' => (string) $f['id']], ['name' => mb_substr($f['name'] ?? $f['id'], 0, 191),
                'status' => $f['status'] ?? null, 'page_id' => $i->cfg('page_id'), 'last_synced_at' => now()]);
            $stats['forms']++;
            foreach ($client->formLeads((string) $f['id'], $since) as $l) {
                $stats['seen']++;
                try {
                    $o = $this->ingest($i, (string) $l['id'], 'backfill', $l + ['form_id' => $f['id']]);
                } catch (MetaException) {
                    $o = 'failed';
                }
                $stats[$o] = ($stats[$o] ?? 0) + 1;
            }
        }
        $i->config = array_merge($i->config ?? [], ['last_backfill_at' => now()->toIso8601ZuluString()]);
        $i->save();

        return $stats;
    }
}
