<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ActivityResource;
use App\Http\Resources\ContactResource;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\SuppressionRecord;
use App\Services\ConsentService;
use App\Services\ContactMatcher;
use App\Services\ContactMergeService;
use App\Services\LeadService;
use App\Services\LeadVisibility;
use App\Services\PhoneNormalizer;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ContactController extends ApiController
{
    public function __construct(private LeadVisibility $visibility) {}

    /** Contacts are visible when the user can see at least one of their leads (or all leads). */
    private function scoped(): Builder
    {
        $q = Contact::query()->whereNull('merged_into_id');
        $ids = $this->visibility->assigneeScope($this->user());
        if ($ids !== null) {
            $q->whereHas('leads', fn ($l) => $ids ? $l->whereIn('assigned_user_id', $ids) : $l->whereRaw('1 = 0'));
        }

        return $q;
    }

    private function visibleContact(Contact $contact): Contact
    {
        abort_unless($this->scoped()->whereKey($contact->id)->exists(), 404);

        return $contact;
    }

    public function index(Request $request)
    {
        abort_unless($this->user()->hasPermission('contacts.view'), 403);
        $q = $this->scoped()->withCount('leads');
        if ($s = trim((string) $request->input('q'))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($s)).'%';
            $digits = ltrim(preg_replace('/\D/', '', $s), '0');
            $q->where(function ($w) use ($like, $digits) {
                $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(company_name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                if (strlen($digits) >= 4) {
                    $w->orWhere('phone_e164', 'like', "%$digits%")->orWhere('whatsapp_e164', 'like', "%$digits%");
                }
            });
        }
        match ($request->input('opt_out')) {
            'opted_out' => $q->where('do_not_contact', true),
            'contactable' => $q->where('do_not_contact', false),
            default => null,
        };
        $page = $q->orderByDesc('id')->paginate($this->perPage());
        $counts = $page->getCollection()->mapWithKeys(fn ($c) => [$c->id => $c->leads_count]);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($c) => (new ContactResource($c))->resolve() + ['leads_count' => $counts[$c->id]])->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Contact $contact, ConsentService $consent)
    {
        abort_unless($this->user()->hasPermission('contacts.view'), 403);
        $this->visibleContact($contact);
        $leads = Lead::where('contact_id', $contact->id)->with(['status', 'assignee', 'source'])->latest()->get()
            ->filter(fn ($l) => $this->visibility->canSee($this->user(), $l))->values();

        return $this->ok([
            'contact' => new ContactResource($contact),
            'leads' => $leads->map(fn ($l) => ['id' => $l->id, 'status' => ['name' => $l->status->name, 'color' => $l->status->color, 'category' => $l->status->category],
                'source' => $l->source?->name, 'assignee' => $l->assignee?->name, 'created_at' => $l->created_at->toIso8601ZuluString(), 'archived' => (bool) $l->archived_at]),
            'consent' => $consent->summary($contact),
            'consent_records' => $contact->consents()->with('recorder:id,name')->limit(50)->get()->map(fn ($r) => [
                'channel' => $r->channel, 'status' => $r->status, 'source' => $r->source, 'evidence' => $r->evidence,
                'by' => $r->recorder?->name, 'at' => $r->recorded_at->toIso8601ZuluString()]),
            'suppressions' => SuppressionRecord::where('contact_id', $contact->id)->latest()->get(['id', 'channel', 'reason', 'lifted_at', 'created_at']),
            'activity' => ActivityResource::collection(Activity::where('contact_id', $contact->id)->whereNull('lead_id')->with('user:id,name')->latest('occurred_at')->limit(30)->get()),
        ]);
    }

    public function update(Request $request, Contact $contact, LeadService $leads)
    {
        abort_unless($this->user()->hasPermission('contacts.update'), 403);
        $this->visibleContact($contact);
        $data = $request->validate([
            'type' => ['nullable', 'in:individual,business'], 'name' => ['nullable', 'string', 'max:160'],
            'company_name' => ['nullable', 'string', 'max:160'], 'email' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'], 'whatsapp' => ['nullable', 'string', 'max:40'],
            'phone_region' => ['nullable', 'string', 'size:2'], 'city' => ['nullable', 'string', 'max:120'],
            'preferred_contact_method' => ['nullable', 'in:phone,whatsapp,email'],
        ]);
        $attrs = $leads->contactAttributes($data);
        foreach (['name', 'company_name', 'city', 'email', 'phone', 'whatsapp'] as $f) {
            if (array_key_exists($f, $data) && ($data[$f] === null || $data[$f] === '')) {
                match ($f) {
                    'email' => $attrs += ['email' => null, 'email_normalized' => null],
                    'phone' => $attrs += ['phone_raw' => null, 'phone_e164' => null],
                    'whatsapp' => $attrs += ['whatsapp_raw' => null, 'whatsapp_e164' => null],
                    default => $attrs[$f] = null,
                };
            }
        }
        $contact->fill($attrs);
        $changes = collect($contact->getDirty())->except('email_normalized')->map(fn ($v, $k) => ['old' => $contact->getOriginal($k), 'new' => $v])->all();
        $contact->save();
        if ($changes) {
            app(\App\Services\ActivityLogger::class)->forContact($contact->id, 'contact_updated', null, ['changes' => $changes]);
        }

        return $this->ok(new ContactResource($contact));
    }

    /** Live duplicate check for the lead form. Reveals only contacts the user may see. */
    public function match(Request $request, PhoneNormalizer $phones, ContactMatcher $matcher, CurrentOrganization $org)
    {
        $data = $request->validate(['phone' => ['nullable', 'string', 'max:40'], 'whatsapp' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'string', 'max:191'], 'phone_region' => ['nullable', 'string', 'size:2']]);
        $region = $data['phone_region'] ?? $org->require()->default_country;
        $p = $phones->normalize($data['phone'] ?? null, $region);
        $w = $phones->normalize($data['whatsapp'] ?? null, $region);
        $matches = $matcher->strongMatches([$p['e164'], $w['e164']], ContactMatcher::normalizeEmail($data['email'] ?? null));
        $visibleIds = $this->scoped()->whereIn('id', $matches->pluck('contact.id'))->pluck('id')->all();

        return $this->ok([
            'phone' => ['e164' => $p['e164'], 'valid' => $p['valid'], 'region' => $p['region']],
            'whatsapp' => ['e164' => $w['e164'], 'valid' => $w['valid'], 'region' => $w['region']],
            'matches' => $matches->map(function ($m) use ($visibleIds) {
                $c = $m['contact'];
                $visible = in_array($c->id, $visibleIds, true);
                $open = Lead::where('contact_id', $c->id)->whereNull('archived_at')->whereHas('status', fn ($q) => $q->where('category', 'open'))->first();

                return [
                    'contact_id' => $visible ? $c->id : null,
                    'display_name' => $visible ? $c->displayName() : 'Existing contact (not in your leads)',
                    'reasons' => $m['reasons'],
                    'has_open_lead' => (bool) $open,
                    'open_lead_id' => $visible && $open && $this->visibility->canSee($this->user(), $open) ? $open->id : null,
                    'do_not_contact' => $c->do_not_contact,
                ];
            })->values(),
        ]);
    }

    public function duplicates(Contact $contact, ContactMatcher $matcher)
    {
        abort_unless($this->user()->hasPermission('contacts.merge'), 403);
        $this->visibleContact($contact);
        $visible = $this->scoped()->pluck('id')->flip();

        return $this->ok($matcher->candidatesFor($contact)->filter(fn ($m) => isset($visible[$m['contact']->id]))
            ->map(fn ($m) => ['contact' => new ContactResource($m['contact']), 'reasons' => $m['reasons'], 'strength' => $m['strength'],
                'leads_count' => Lead::where('contact_id', $m['contact']->id)->count()])->values());
    }

    public function merge(Request $request, Contact $contact, ContactMergeService $merge)
    {
        abort_unless($this->user()->hasPermission('contacts.merge'), 403);
        $this->visibleContact($contact);
        $data = $request->validate(['source_contact_id' => ['required', 'integer'], 'confirm' => ['accepted']]);
        $source = $this->visibleContact(Contact::findOrFail($data['source_contact_id']));

        return $this->ok(new ContactResource($merge->merge($contact, $source)));
    }

    public function recordConsent(Request $request, Contact $contact, ConsentService $consent)
    {
        abort_unless($this->user()->hasPermission('consent.manage'), 403);
        $this->visibleContact($contact);
        $data = $request->validate([
            'channel' => ['required', 'in:'.implode(',', ConsentService::CHANNELS)],
            'status' => ['required', 'in:granted,withdrawn'],
            'source' => ['required', 'in:verbal,web_form,whatsapp_message,instant_form,written,other'],
            'evidence' => ['nullable', 'string', 'max:500'],
        ]);
        if ($data['status'] === 'withdrawn') {
            $consent->optOut($contact, $data['channel'], $data['evidence'] ?: 'Consent withdrawn', $data['source']);
        } else {
            if (SuppressionRecord::where('contact_id', $contact->id)->whereIn('channel', ['all', $data['channel']])->whereNull('lifted_at')->exists()) {
                throw new \App\Exceptions\DomainRuleException('This contact is suppressed for that channel. Lift the suppression first (audited).', 'suppressed');
            }
            $consent->record($contact, $data['channel'], 'granted', $data['source'], $data['evidence'] ?? null);
        }

        return $this->ok($consent->summary($contact->fresh()));
    }

    public function optOut(Request $request, Contact $contact, ConsentService $consent)
    {
        abort_unless($this->user()->hasPermission('consent.manage'), 403);
        $this->visibleContact($contact);
        $data = $request->validate(['channel' => ['required', 'in:all,'.implode(',', ConsentService::CHANNELS)], 'reason' => ['required', 'string', 'max:255']]);
        $consent->optOut($contact, $data['channel'], $data['reason']);

        return $this->ok($consent->summary($contact->fresh()));
    }

    /** Irreversible legal erasure. Requires an explicit reason; audited. */
    public function erase(Request $request, Contact $contact, \App\Services\ContactErasureService $erasure)
    {
        abort_unless($this->user()->hasPermission('contacts.erase'), 403);
        abort_if($request->attributes->get('api_token') !== null, 403, 'Erasure is only available from the web app.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500'], 'confirm' => ['required', 'in:ERASE']]);

        return $this->ok($erasure->erase($contact, $data['reason']) + ['erased' => true]);
    }

    public function liftSuppression(Request $request, SuppressionRecord $suppression, ConsentService $consent)
    {
        abort_unless($this->user()->hasPermission('consent.manage') && $this->user()->hasAnyPermission('contacts.merge', 'settings.manage'), 403);
        if ($suppression->contact_id) {
            $this->visibleContact(Contact::findOrFail($suppression->contact_id));
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        abort_if($suppression->lifted_at, 409, 'Already lifted.');
        $consent->lift($suppression, $data['reason']);

        return $this->ok(['lifted' => true]);
    }
}
