@extends('layouts.app')
@section('title', 'Settings')
@section('content')
<div class="lf-page-head"><h1>Settings</h1></div>
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item settings-only"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#s-org" role="tab">{{ __('Organization') }}</button></li>
    <li class="nav-item settings-only"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#s-biz" role="tab">{{ __('Business & automation') }}</button></li>
    <li class="nav-item settings-only"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#s-pipeline" role="tab">Pipeline stages</button></li>
    <li class="nav-item settings-only"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#s-sources" role="tab">Lead sources</button></li>
    <li class="nav-item settings-only"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#s-outcomes" role="tab">Call outcomes</button></li>
    <li class="nav-item roles-only"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#s-roles" role="tab" id="rolesTab">Roles &amp; permissions</button></li>
</ul>
<div class="tab-content">
    <div class="tab-pane fade show active settings-only" id="s-org" role="tabpanel"><div class="card"><form class="card-body" id="orgForm" novalidate>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="o-name">Business name</label><input class="form-control" id="o-name" name="name" maxlength="160" required></div>
            <div class="col-md-6"><label class="form-label" for="o-tz">Time zone</label><select class="form-select" id="o-tz" name="timezone"></select></div>
            <div class="col-md-3"><label class="form-label" for="o-cur">Default currency</label><input class="form-control" id="o-cur" name="currency" maxlength="3"></div>
            <div class="col-md-3"><label class="form-label" for="o-country">Default phone country</label><select class="form-select" id="o-country" name="default_country" data-fill="regions"></select></div>
            <div class="col-md-6"><label class="form-label" for="o-ident">Required contact identifier</label><select class="form-select" id="o-ident" name="settings[required_identifier]"><option value="phone_or_whatsapp">A valid phone or WhatsApp number</option><option value="any">Phone, WhatsApp or email</option></select></div>
            <div class="col-md-6"><label class="form-label" for="o-sla">First-contact target (minutes)</label><input class="form-control" type="number" min="5" max="10080" id="o-sla" name="settings[first_contact_sla_minutes]"><div class="form-text">Used by the "First-contact SLA missed" automation rule.</div></div>
            <div class="col-md-6 align-self-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="o-dup" name="settings[allow_duplicate_open_leads]" value="1"><label class="form-check-label" for="o-dup">Allow more than one open lead per contact without confirmation</label></div></div>
        </div>
        <h3 class="fs-6 mt-4 mb-2">Security</h3>
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="o-2fa" name="settings[require_2fa]" value="1"><label class="form-check-label" for="o-2fa">Require two-factor authentication for every user</label></div>
        <div class="form-text">Users without an authenticator app set up are sent to their profile to enrol before they can use the CRM. Make sure you have enrolled yourself first.</div>
        <h3 class="fs-6 mt-4 mb-2">Data retention</h3>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="o-ret-archive">Archive closed leads after (days)</label><input class="form-control" type="number" min="30" max="3650" id="o-ret-archive" name="settings[retention_archive_after_days]" placeholder="never"><div class="form-text">Won/lost leads older than this are archived (hidden from lists, kept for reports). Leave empty to keep forever.</div></div>
            <div class="col-md-2"><label class="form-label" for="o-ret-wh">Webhook payloads (days)</label><input class="form-control" type="number" min="7" max="365" id="o-ret-wh" name="settings[retention_purge_webhooks_days]"></div>
            <div class="col-md-2"><label class="form-label" for="o-ret-notif">Notifications (days)</label><input class="form-control" type="number" min="7" max="365" id="o-ret-notif" name="settings[retention_purge_notifications_days]"></div>
            <div class="col-md-2"><label class="form-label" for="o-ret-ai">AI transcripts (days)</label><input class="form-control" type="number" min="30" max="730" id="o-ret-ai" name="settings[retention_purge_ai_days]"></div>
            <div class="col-md-2"><label class="form-label" for="o-ret-auto">Automation history (days)</label><input class="form-control" type="number" min="30" max="730" id="o-ret-auto" name="settings[retention_purge_automation_days]"></div>
            <div class="col-12 form-text">Runs nightly. Retention is housekeeping, not legal erasure — to erase a person's data use "Erase personal data" on the contact page, which is audited and keeps only the opt-out hash.</div>
        </div>
        <div class="mt-3"><button class="btn btn-primary" type="submit">Save organization settings</button></div>
    </form></div></div>

    <div class="tab-pane fade settings-only" id="s-biz" role="tabpanel"><form class="card" id="bizForm" novalidate><div class="card-body"><div class="row g-3">
        <div class="col-md-4"><label class="form-label" for="b-ind">{{ __('Industry') }}</label><select class="form-select" id="b-ind" name="industry"></select><div class="form-text">{{ __('Used for AI assistant presets.') }}</div></div>
        <div class="col-md-4"><label class="form-label" for="b-locale">{{ __('Default interface language') }}</label><select class="form-select" id="b-locale" name="default_locale"></select></div>
        <div class="col-md-4"><label class="form-label" for="b-color">{{ __('Brand colour') }}</label><div class="input-group"><input class="form-control form-control-color" type="color" id="b-color" name="brand_color" value="#2563eb"><button class="btn btn-light" type="button" id="b-color-reset">{{ __('Default') }}</button></div></div>
        <div class="col-md-6"><label class="form-label" for="b-logo">{{ __('Logo') }}</label><input class="form-control" type="file" id="b-logo" accept="image/png,image/jpeg,image/webp"><div class="form-text">{{ __('PNG, JPG or WebP, max 1 MB. Shown in the sidebar.') }}</div></div>
        <div class="col-md-6"><div class="form-label">{{ __('Business hours') }}</div><div class="hours-grid" id="b-hours"></div><div class="form-text">{{ __('Used for after-hours auto-replies and AI scheduling, in the organization time zone.') }}</div></div>
        <div class="col-12"><hr class="my-1"><div class="small-caps mb-2">{{ __('Automatic assignment of new WhatsApp leads') }}</div>
            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="aa-on"><label class="form-check-label" for="aa-on">{{ __('Round-robin new leads to available salespeople') }}</label></div>
            <div class="row g-2"><div class="col-md-4"><label class="form-label small" for="aa-team">{{ __('Only this team') }}</label><select class="form-select form-select-sm" id="aa-team"></select></div>
            <div class="col-md-8"><div class="form-label small">{{ __('Or only these people') }}</div><div class="kv-chips" id="aa-users"></div></div></div>
            <div class="form-text">{{ __('Default pool: active Sales Representatives who accept assignments.') }}</div></div>
        <div class="col-12"><hr class="my-1"><div class="small-caps mb-2">{{ __('Lead scoring weights') }}</div><div class="row g-2" id="b-weights"></div>
            <div class="form-text">{{ __('Score = 20 + sum of matching factors (0–100). Hot ≥ 60, Warm ≥ 30. Only behaviour and stated needs are used.') }}</div></div>
    </div></div><div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit">{{ __('Save business settings') }}</button></div></form></div>

    <div class="tab-pane fade settings-only" id="s-pipeline" role="tabpanel"><div class="card">
        <div class="card-header d-flex align-items-center">Stages<button class="btn btn-sm btn-primary ms-auto" id="addStatus"><i class="bi bi-plus-lg"></i> Add stage</button></div>
        <div class="card-body small text-muted pb-0">Drag to reorder. The <em>meaning</em> drives reporting and automatic moves (e.g. an unanswered call can only ever mark "Contact Attempted"), so renaming is always safe.</div>
        <div class="table-wrap"><table class="table align-middle"><thead><tr><th></th><th>Name</th><th>Meaning</th><th>Category</th><th>Colour</th><th>Reason required</th><th class="text-end">Leads</th><th>Default</th><th>Active</th></tr></thead><tbody id="statusBody"></tbody></table></div>
    </div></div>

    <div class="tab-pane fade settings-only" id="s-sources" role="tabpanel"><div class="card">
        <div class="card-header d-flex align-items-center">Lead sources<button class="btn btn-sm btn-primary ms-auto" id="addSource"><i class="bi bi-plus-lg"></i> Add source</button></div>
        <div class="table-wrap"><table class="table align-middle"><thead><tr><th>Name</th><th>Platform</th><th>Paid</th><th>Active</th></tr></thead><tbody id="sourceBody"></tbody></table></div>
    </div></div>

    <div class="tab-pane fade settings-only" id="s-outcomes" role="tabpanel"><div class="card">
        <div class="card-header d-flex align-items-center">Call outcomes<button class="btn btn-sm btn-primary ms-auto" id="addOutcome"><i class="bi bi-plus-lg"></i> Add outcome</button></div>
        <div class="table-wrap"><table class="table align-middle"><thead><tr><th>Name</th><th>Someone answered</th><th>Creates callback</th><th>Moves lead to</th><th>Active</th></tr></thead><tbody id="outcomeBody"></tbody></table></div>
    </div></div>

    <div class="tab-pane fade roles-only" id="s-roles" role="tabpanel"><div class="card">
        <div class="card-body small text-muted pb-0">Permissions are enforced on the server for every request. Changes are recorded in the audit log.</div>
        <div class="table-wrap"><table class="table table-sm perm-matrix align-middle" id="permMatrix"></table></div>
        <div class="card-footer bg-white text-end"><button class="btn btn-primary btn-sm" id="savePerms">Save permission changes</button></div>
    </div></div>
</div>

<div class="modal fade" id="itemModal" tabindex="-1" aria-labelledby="itemModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" id="itemForm" novalidate>
    <div class="modal-header"><h2 class="modal-title fs-5" id="itemModalTitle">Add</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body" id="itemBody"></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
</form></div></div>
@endsection
@push('vendor-scripts')<script src="/vendor/sortable/Sortable.min.js"></script>@endpush
@push('scripts')<script src="/js/pages/settings.js?v={{ config('app.asset_version') }}"></script>@endpush
