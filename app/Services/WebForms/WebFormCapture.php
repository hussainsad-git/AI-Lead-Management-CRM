<?php

namespace App\Services\WebForms;

use App\Exceptions\DomainRuleException;
use App\Models\Lead;
use App\Models\WebForm;
use App\Models\WebFormSubmission;
use App\Services\ActivityLogger;
use App\Services\ConsentService;
use App\Services\LeadService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a public form submission into a lead. Accepted field names (case-insensitive, hyphens/spaces → underscores):
 *   name, full_name | phone, mobile, tel | whatsapp | email | city | company
 *   message, notes | interest, product, service | budget | location | preferred_contact (phone|whatsapp|email)
 *   consent, consent_whatsapp, consent_phone, consent_email (truthy = granted)
 *   utm_source, utm_medium, utm_campaign, utm_content, utm_term, fbclid, gclid, page_url, referrer
 * Anything else is kept in the submission payload (for review) but not written to the lead.
 * Honeypot: a filled "website" (or "_hp") field is rejected silently with a 200 so bots learn nothing.
 */
class WebFormCapture
{
    public const HONEYPOT = ['website', '_hp'];

    private const ALIASES = [
        'full_name' => 'name', 'fullname' => 'name', 'your_name' => 'name',
        'mobile' => 'phone', 'tel' => 'phone', 'telephone' => 'phone', 'phone_number' => 'phone', 'contact_number' => 'phone',
        'whatsapp_number' => 'whatsapp', 'wa' => 'whatsapp',
        'email_address' => 'email', 'mail' => 'email',
        'notes' => 'message', 'comments' => 'message', 'enquiry' => 'message', 'inquiry' => 'message',
        'product' => 'interest', 'service' => 'interest', 'product_interest' => 'interest', 'interested_in' => 'interest',
        'company_name' => 'company', 'business' => 'company',
        'area' => 'location', 'address' => 'location',
        'preferred_contact_method' => 'preferred_contact',
        'ref' => 'referrer', 'referer' => 'referrer', 'url' => 'page_url', 'landing_page' => 'page_url',
    ];

    public function __construct(
        private LeadService $leads,
        private ActivityLogger $activity,
        private ConsentService $consent,
        private CurrentOrganization $org,
    ) {}

    /** Normalises keys and strips the honeypot. Returns [fields, honeypotTripped]. */
    public function normalise(array $input): array
    {
        $out = [];
        $tripped = false;
        foreach ($input as $k => $v) {
            if (is_array($v)) {
                $v = implode(', ', array_filter(array_map('strval', array_filter($v, 'is_scalar'))));
            }
            if (! is_scalar($v) && $v !== null) {
                continue;
            }
            $key = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', trim((string) $k)));
            $key = self::ALIASES[$key] ?? $key;
            if (in_array($key, self::HONEYPOT, true)) {
                $tripped = $tripped || trim((string) $v) !== '';
                continue;
            }
            if (str_starts_with($key, '_')) {
                continue; // framework/junk fields (_token, _method …)
            }
            $out[$key] = is_string($v) ? mb_substr(trim($v), 0, 2000) : $v;
        }

        return [$out, $tripped];
    }

    /**
     * @return array{status:string, lead_id:?int, errors?:array}
     *
     * @throws ValidationException when identifiers are missing/invalid (caller returns 422 to the site)
     */
    public function capture(WebForm $form, array $fields, ?string $ipHash): array
    {
        return $this->org->runAs($form->organization, function () use ($form, $fields, $ipHash) {
            $record = fn (string $status, ?int $leadId = null, ?string $error = null) => $this->log($form, $fields, $status, $leadId, $error, $ipHash);

            $contact = array_filter([
                'name' => $fields['name'] ?? null,
                'phone' => $fields['phone'] ?? null,
                'whatsapp' => $fields['whatsapp'] ?? null,
                'email' => $fields['email'] ?? null,
                'city' => $fields['city'] ?? null,
                'company_name' => $fields['company'] ?? null,
                'type' => ! empty($fields['company']) ? 'business' : null,
                'phone_region' => isset($fields['country']) && preg_match('/^[A-Za-z]{2}$/', $fields['country']) ? strtoupper($fields['country']) : null,
                'preferred_contact_method' => in_array($fields['preferred_contact'] ?? null, ['phone', 'whatsapp', 'email'], true) ? $fields['preferred_contact'] : null,
            ], fn ($v) => $v !== null && $v !== '');

            // Validate identifiers up front so a bad submission never creates a half-record.
            try {
                $attrs = $this->leads->contactAttributes($contact);
            } catch (ValidationException $e) {
                $record('invalid', null, implode(' ', array_map(fn ($m) => implode(' ', $m), $e->errors())));
                throw $e;
            }

            // Absorb double-submits: same identifier on the same form within 10 minutes.
            $ident = $attrs['phone_e164'] ?? $attrs['whatsapp_e164'] ?? $attrs['email_normalized'] ?? null;
            if ($ident) {
                $recent = WebFormSubmission::where('web_form_id', $form->id)->where('created_at', '>', now()->subMinutes(10))
                    ->whereIn('status', ['created', 'linked'])->whereNotNull('lead_id')->latest('id')->limit(50)->get()
                    ->first(fn ($s) => in_array($ident, [$s->payload['_phone_e164'] ?? null, $s->payload['_whatsapp_e164'] ?? null, $s->payload['_email'] ?? null], true));
                if ($recent) {
                    $record('duplicate', $recent->lead_id);

                    return ['status' => 'duplicate', 'lead_id' => $recent->lead_id];
                }
            }
            $fields['_phone_e164'] = $attrs['phone_e164'] ?? null;
            $fields['_whatsapp_e164'] = $attrs['whatsapp_e164'] ?? null;
            $fields['_email'] = $attrs['email_normalized'] ?? null;

            $message = $fields['message'] ?? null;
            $pageUrl = isset($fields['page_url']) && filter_var($fields['page_url'], FILTER_VALIDATE_URL) ? $fields['page_url'] : null;
            $referral = array_filter(['page_url' => $pageUrl, 'referrer' => $fields['referrer'] ?? null, 'fbclid' => $fields['fbclid'] ?? null, 'gclid' => $fields['gclid'] ?? null,
                'utm_content' => $fields['utm_content'] ?? null, 'utm_term' => $fields['utm_term'] ?? null, 'form' => $form->name]);
            $platform = match (true) {
                ! empty($fields['fbclid']) => 'facebook',
                ! empty($fields['gclid']) => 'google',
                in_array(strtolower($fields['utm_source'] ?? ''), ['facebook', 'fb', 'instagram', 'ig', 'meta'], true) => in_array(strtolower($fields['utm_source']), ['instagram', 'ig'], true) ? 'instagram' : 'facebook',
                default => 'web',
            };
            $data = [
                'contact' => $contact,
                'lead_source_id' => $form->lead_source_id,
                'product_interest' => isset($fields['interest']) ? mb_substr($fields['interest'], 0, 191) : null,
                'budget_amount' => isset($fields['budget']) && is_numeric(str_replace([',', ' '], '', (string) $fields['budget'])) ? (float) str_replace([',', ' '], '', (string) $fields['budget']) : null,
                'location' => isset($fields['location']) ? mb_substr($fields['location'], 0, 160) : null,
                'source_notes' => 'Web form: '.$form->name.($pageUrl ? ' · '.$pageUrl : ''),
                'note' => $message ? mb_substr($message, 0, 5000) : null,
                'tags' => $form->tags ?: [],
                'assigned_user_id' => $form->assigned_user_id,
                'attribution' => array_filter([
                    'platform' => $platform,
                    'source' => $fields['utm_source'] ?? ($pageUrl ? parse_url($pageUrl, PHP_URL_HOST) : null),
                    'medium' => $fields['utm_medium'] ?? 'web_form',
                    'campaign_id' => $form->campaign_id,
                    'campaign_name' => $form->campaign_id ? null : ($fields['utm_campaign'] ?? null),
                    'referral' => $referral ?: null,
                ], fn ($v) => $v !== null && $v !== ''),
                'attribution_via' => 'web_form',
                'consents' => $this->consents($fields),
                'consent_source' => 'web_form',
                'consent_evidence' => mb_substr('Form "'.$form->name.'"'.($pageUrl ? ' at '.$pageUrl : '').' on '.now()->toIso8601ZuluString(), 0, 500),
            ];

            try {
                $lead = $this->leads->create($data, null, 'web_form');
                $this->bump($form, $fields, 'created', $lead->id, $ipHash);

                return ['status' => 'created', 'lead_id' => $lead->id];
            } catch (DomainRuleException $e) {
                if ($e->errorCode !== 'duplicate_open_lead') {
                    $record('rejected', null, $e->getMessage());
                    throw $e;
                }
                // The contact already has an open lead: log the new inquiry on it instead of creating a duplicate.
                $existing = $this->openLeadFor($attrs);
                if (! $existing) {
                    $record('rejected', null, $e->getMessage());
                    throw $e;
                }
                DB::transaction(function () use ($existing, $form, $message, $pageUrl, $fields, $data) {
                    $this->activity->forLead($existing, 'web_form_submission', $message ?: 'New inquiry (no message)', ['form' => $form->name, 'page_url' => $pageUrl, 'interest' => $fields['interest'] ?? null]);
                    $existing->forceFill(['last_activity_at' => now()])->saveQuietly();
                    foreach ($data['consents'] as $channel => $granted) {
                        if ($granted) {
                            $this->consent->record($existing->contact, $channel, 'granted', 'web_form', $data['consent_evidence']);
                        }
                    }
                });
                $this->bump($form, $fields, 'linked', $existing->id, $ipHash);

                return ['status' => 'linked', 'lead_id' => $existing->id];
            }
        });
    }

    private function consents(array $f): array
    {
        $truthy = fn ($v) => in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on', 'y'], true);
        $all = isset($f['consent']) && $truthy($f['consent']);

        return array_filter([
            'whatsapp' => $all || (isset($f['consent_whatsapp']) && $truthy($f['consent_whatsapp'])),
            'phone' => $all || (isset($f['consent_phone']) && $truthy($f['consent_phone'])),
            'email' => $all || (isset($f['consent_email']) && $truthy($f['consent_email'])),
        ]);
    }

    private function openLeadFor(array $attrs): ?Lead
    {
        $q = Lead::query()->whereNull('archived_at')->whereHas('status', fn ($s) => $s->where('category', 'open'))
            ->whereHas('contact', function ($c) use ($attrs) {
                $c->whereNull('merged_into_id')->where(function ($w) use ($attrs) {
                    $any = false;
                    foreach (array_filter([$attrs['phone_e164'] ?? null, $attrs['whatsapp_e164'] ?? null]) as $p) {
                        $w->orWhere('phone_e164', $p)->orWhere('whatsapp_e164', $p);
                        $any = true;
                    }
                    if (! empty($attrs['email_normalized'])) {
                        $w->orWhere('email_normalized', $attrs['email_normalized']);
                        $any = true;
                    }
                    if (! $any) {
                        $w->whereRaw('1 = 0');
                    }
                });
            });

        return $q->with('contact')->latest('id')->first();
    }

    private function bump(WebForm $form, array $fields, string $status, int $leadId, ?string $ipHash): void
    {
        $this->log($form, $fields, $status, $leadId, null, $ipHash);
        $form->newQuery()->whereKey($form->id)->update(['submissions_count' => DB::raw('submissions_count + 1'), 'last_submission_at' => now()]);
    }

    private function log(WebForm $form, array $fields, string $status, ?int $leadId, ?string $error, ?string $ipHash): void
    {
        $payload = $fields;
        unset($payload['_phone_e164'], $payload['_whatsapp_e164'], $payload['_email']);
        // keep the normalised identifiers for the duplicate window only
        $payload['_phone_e164'] = $fields['_phone_e164'] ?? null;
        $payload['_whatsapp_e164'] = $fields['_whatsapp_e164'] ?? null;
        $payload['_email'] = $fields['_email'] ?? null;
        $s = new WebFormSubmission(['web_form_id' => $form->id, 'lead_id' => $leadId, 'status' => $status, 'ip_hash' => $ipHash, 'payload' => $payload, 'error' => $error ? mb_substr($error, 0, 500) : null]);
        $s->organization_id = $form->organization_id;
        $s->save();
    }
}
