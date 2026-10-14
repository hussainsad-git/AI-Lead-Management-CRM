<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Campaign;
use App\Models\LeadSource;
use App\Models\User;
use App\Models\WebForm;
use App\Services\AuditLogger;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WebFormController extends ApiController
{
    private function guard(): void
    {
        abort_unless($this->user()->hasPermission('integrations.manage'), 403);
    }

    public function index()
    {
        $this->guard();
        $forms = WebForm::with(['source:id,name', 'campaign:id,name', 'assignee:id,name'])->orderBy('name')->get();
        $recent = \App\Models\WebFormSubmission::selectRaw('web_form_id, status, COUNT(*) as n')->where('created_at', '>', now()->subDays(7))->groupBy('web_form_id', 'status')->get()->groupBy('web_form_id');

        return $this->ok([
            'forms' => $forms->map(fn ($f) => $this->present($f, $recent->get($f->id))),
            'sources' => LeadSource::where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'key']),
            'users' => User::query()->inCurrentOrganization()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'campaigns' => Campaign::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $this->guard();
        $data = $this->validated($request);
        $form = new WebForm($data + ['key' => WebForm::generateKey(), 'created_by' => $this->user()->id]);
        $form->save();
        $audit->log('web_form.created', $form, [], ['name' => $form->name]);

        return $this->ok($this->present($form->load(['source:id,name', 'campaign:id,name', 'assignee:id,name'])), [], 201);
    }

    public function update(Request $request, WebForm $form, AuditLogger $audit)
    {
        $this->guard();
        $before = $form->only(['name', 'is_active', 'allowed_origins', 'assigned_user_id', 'lead_source_id', 'campaign_id', 'redirect_url']);
        $form->fill($this->validated($request, $form))->save();
        $audit->log('web_form.updated', $form, ['before' => $before, 'after' => $form->only(array_keys($before))]);

        return $this->ok($this->present($form->fresh(['source:id,name', 'campaign:id,name', 'assignee:id,name'])));
    }

    public function rotate(WebForm $form, AuditLogger $audit)
    {
        $this->guard();
        $form->forceFill(['key' => WebForm::generateKey()])->save();
        $audit->log('web_form.key_rotated', $form, [], ['name' => $form->name]);

        return $this->ok($this->present($form->fresh(['source:id,name', 'campaign:id,name', 'assignee:id,name'])));
    }

    public function destroy(WebForm $form, AuditLogger $audit)
    {
        $this->guard();
        $audit->log('web_form.deleted', $form, [], ['name' => $form->name]);
        $form->delete(); // submissions cascade; leads already created are untouched

        return $this->ok(['deleted' => true]);
    }

    public function submissions(Request $request, WebForm $form)
    {
        $this->guard();
        $rows = $form->submissions()->latest('id')->limit(100)->get()->map(fn ($s) => [
            'id' => $s->id, 'status' => $s->status, 'lead_id' => $s->lead_id, 'error' => $s->error, 'at' => $s->created_at->toIso8601ZuluString(),
            'fields' => collect($s->payload ?? [])->except(['_phone_e164', '_whatsapp_e164', '_email'])->map(fn ($v) => is_string($v) ? mb_substr($v, 0, 120) : $v),
        ]);

        return $this->ok($rows);
    }

    private function validated(Request $request, ?WebForm $existing = null): array
    {
        $org = app(CurrentOrganization::class)->id();
        $data = $request->validate([
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:120'],
            'lead_source_id' => [$existing ? 'sometimes' : 'required', 'integer', Rule::exists('lead_sources', 'id')->where('organization_id', $org)],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')->where('organization_id', $org)],
            'assigned_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $org)->where('is_active', true)],
            'allowed_origins' => ['nullable', 'array', 'max:20'],
            'allowed_origins.*' => ['string', 'max:200', 'regex:#^https?://[a-z0-9.-]+(:\d+)?$#i'],
            'redirect_url' => ['nullable', 'url:https,http', 'max:500'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['lead_source_id' => 'lead source', 'allowed_origins.*' => 'allowed origin (use scheme://host, no path)']);
        if (array_key_exists('allowed_origins', $data)) {
            $data['allowed_origins'] = array_values(array_unique(array_map(fn ($o) => strtolower(rtrim($o, '/')), $data['allowed_origins'] ?? []))) ?: null;
        }
        if (! empty($data['redirect_url'])) {
            $origins = $data['allowed_origins'] ?? $existing?->allowed_origins ?? [];
            $tmp = new WebForm(['allowed_origins' => $origins]);
            if ($origins && ! $tmp->allowsOrigin($data['redirect_url'], null)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['redirect_url' => 'The redirect URL must be on one of the allowed origins.']);
            }
        }

        return $data;
    }

    private function present(WebForm $f, $recent = null): array
    {
        $counts = ['created' => 0, 'linked' => 0, 'duplicate' => 0, 'rejected' => 0, 'invalid' => 0];
        foreach ($recent ?? [] as $r) {
            $counts[$r->status] = (int) $r->n;
        }

        return [
            'id' => $f->id, 'name' => $f->name, 'key' => $f->key, 'endpoint' => $f->endpoint(), 'is_active' => $f->is_active,
            'lead_source_id' => $f->lead_source_id, 'source' => $f->source?->name, 'campaign_id' => $f->campaign_id, 'campaign' => $f->campaign?->name,
            'assigned_user_id' => $f->assigned_user_id, 'assignee' => $f->assignee?->name,
            'allowed_origins' => $f->allowed_origins ?? [], 'redirect_url' => $f->redirect_url, 'tags' => $f->tags ?? [],
            'submissions_count' => $f->submissions_count, 'last_submission_at' => $f->last_submission_at?->toIso8601ZuluString(),
            'last_7_days' => $counts,
            'embed_html' => $this->embed($f),
        ];
    }

    /** Minimal plain-HTML snippet; works without JavaScript and keeps no secret beyond the form key. */
    private function embed(WebForm $f): string
    {
        $u = e($f->endpoint());

        return <<<HTML
<form method="post" action="{$u}" accept-charset="UTF-8">
  <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" class="hp">
  <label>Name <input name="name" required></label>
  <label>Phone / WhatsApp <input name="phone" type="tel" required></label>
  <label>Email <input name="email" type="email"></label>
  <label>Message <textarea name="message"></textarea></label>
  <label><input type="checkbox" name="consent_whatsapp" value="1"> You may contact me on WhatsApp</label>
  <input type="hidden" name="page_url" value="">
  <button type="submit">Send</button>
</form>
<!-- Hide the honeypot with CSS: .hp{position:absolute;left:-9999px} and set page_url from location.href if you use JS. -->
HTML;
    }
}
