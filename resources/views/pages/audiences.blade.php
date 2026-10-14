@extends('layouts.app')
@section('title', 'Audience Segments')
@section('content')
<div class="lf-page-head">
    <h1>{{ __('Audience Segments') }}</h1>
    <div class="lf-actions"><button class="btn btn-primary btn-sm" id="newSeg" type="button"><i class="bi bi-plus-lg"></i> {{ __('New segment') }}</button></div>
</div>
<p class="small text-muted mb-3">{{ __('Segments are live queries over your leads. Before anything leaves the CRM, every person is checked again: opted-out and Do Not Contact people, anyone on an attached exclusion list, people without a phone or email, and anyone failing the segment\'s consent policy are left out. Counts are CRM counts — how many Meta can match is decided by Meta.') }}</p>

<div class="row g-3">
    <div class="col-xl-8"><div class="card">
        <div class="card-header">{{ __('Segments') }}</div>
        <div class="table-wrap"><table class="table align-middle table-dense mb-0">
            <thead><tr><th>{{ __('Segment') }}</th><th class="text-end">{{ __('Members') }}</th><th class="text-end">{{ __('Eligible') }}</th><th>{{ __('Left out') }}</th><th>{{ __('Meta') }}</th><th>{{ __('Counted') }}</th><th></th></tr></thead>
            <tbody id="segBody"></tbody>
        </table></div>
    </div></div>
    <div class="col-xl-4">
        <div class="card"><div class="card-header">{{ __('Exclusion lists') }}</div><div class="card-body small" id="exList"></div></div>
        <div class="card mt-3"><div class="card-header">{{ __('How eligibility works') }}</div><div class="card-body small">
            <ul class="mb-0 ps-3">
                <li>{{ __('Opt-outs and Do Not Contact are always excluded — this cannot be switched off.') }}</li>
                <li>{{ __('Consent policy "Not opted out" leaves out anyone who withdrew ads consent; "Explicit consent" only includes people with a recorded ads-audience consent.') }}</li>
                <li>{{ __('Exclusion lists subtract people (for example existing customers from an acquisition audience).') }}</li>
                <li>{{ __('Uploads to Meta are SHA-256 hashed phone, email and country only — no names.') }}</li>
            </ul>
        </div></div>
    </div>
</div>

<div class="modal fade" id="segModal" tabindex="-1" aria-labelledby="segTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><form class="modal-content" id="segForm" novalidate>
    <div class="modal-header"><h2 class="modal-title fs-5" id="segTitle">{{ __('Segment') }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <div class="row g-2 mb-3">
            <div class="col-md-5"><label class="form-label" for="s-name">{{ __('Name') }}</label><input class="form-control" id="s-name" name="name" maxlength="150"></div>
            <div class="col-md-5"><label class="form-label" for="s-desc">{{ __('Description') }}</label><input class="form-control" id="s-desc" name="description" maxlength="500"></div>
            <div class="col-md-2"><label class="form-label" for="s-kind">{{ __('Type') }}</label><select class="form-select" id="s-kind" name="kind"><option value="audience">{{ __('Audience') }}</option><option value="exclusion">{{ __('Exclusion list') }}</option></select></div>
        </div>
        <h3 class="fs-6 mb-2">{{ __('Include leads where') }}
            <select class="form-select form-select-sm d-inline-block w-auto ms-2" id="s-match" aria-label="{{ __('Match') }}"><option value="all">{{ __('all groups match') }}</option><option value="any">{{ __('any group matches') }}</option></select></h3>
        <div id="groupList"></div>
        <button class="btn btn-light btn-sm mb-3" type="button" id="addGroup"><i class="bi bi-plus"></i> {{ __('Add group') }}</button>
        <div id="audienceOnly">
            <div class="row g-2 mb-2">
                <div class="col-md-6"><label class="form-label" for="s-consent">{{ __('Consent policy') }}</label><select class="form-select" id="s-consent" name="consent_policy"></select></div>
                <div class="col-md-6"><label class="form-label" for="s-excl">{{ __('Exclusion lists to apply') }}</label><select class="form-select" id="s-excl" multiple size="3"></select></div>
            </div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="s-auto"><label class="form-check-label" for="s-auto">{{ __('Auto-sync to Meta daily (when connected)') }}</label></div>
        </div>
        <div class="alert alert-light border mt-3 mb-0 small" id="estimate">{{ __('Add conditions to see an estimate.') }}</div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" id="estBtn">{{ __('Estimate') }}</button><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button><button class="btn btn-primary" type="submit">{{ __('Save segment') }}</button></div>
</form></div></div>

<div class="modal fade" id="prevModal" tabindex="-1" aria-labelledby="prevTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="prevTitle">{{ __('Preview') }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body" id="prevBody"></div>
    <div class="modal-footer flex-wrap gap-2" id="prevActions"></div>
</div></div></div>
@endsection
@push('scripts')<script src="/js/pages/audiences.js?v={{ config('app.asset_version') }}"></script>@endpush
