@extends('layouts.app')
@section('title', 'Platform Admin')
@section('content')
<div class="lf-page-head"><h1>{{ __('Platform Admin') }}</h1><div class="lf-actions"><button class="btn btn-primary btn-sm" id="newOrg"><i class="bi bi-plus-lg"></i> {{ __('New business') }}</button></div></div>
<p class="small text-muted">{{ __('Each business is an isolated workspace with its own users, leads, WhatsApp number, AI assistant and knowledge base. This page shows usage counts only — not customer records.') }}</p>
<div class="row g-2 mb-3" id="sysCards"></div>
<div class="card mb-3"><div class="card-header d-flex align-items-center">{{ __('Production readiness') }} <span class="small text-muted ms-2">php artisan leadflow:doctor</span><span class="ms-auto" id="doctorSummary"></span></div><div class="card-body p-0"><ul class="list-group list-group-flush small" id="doctorList"></ul></div></div>
<div class="card"><div class="card-header">{{ __('Businesses') }}</div><div class="table-wrap"><table class="table align-middle table-dense"><thead><tr>
    <th>{{ __('Business') }}</th><th>{{ __('Industry') }}</th><th class="text-end">{{ __('Users') }}</th><th class="text-end">{{ __('Leads') }}</th><th class="text-end">{{ __('Conversations') }}</th>
    <th class="text-end">{{ __('Messages 30d') }}</th><th class="text-end">{{ __('AI calls 30d') }}</th><th>{{ __('Connected') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead><tbody id="orgBody"></tbody></table></div></div>

<div class="modal fade" id="orgModal" tabindex="-1" aria-labelledby="orgTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" id="orgForm" novalidate autocomplete="off">
    <div class="modal-header"><h2 class="modal-title fs-5" id="orgTitle">{{ __('New business') }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><div class="row g-2">
        <div class="col-12"><label class="form-label" for="o2-name">{{ __('Business name') }}</label><input class="form-control" id="o2-name" name="name" maxlength="160" required></div>
        <div class="col-6"><label class="form-label" for="o2-ind">{{ __('Industry') }}</label><select class="form-select" id="o2-ind" name="industry"></select></div>
        <div class="col-6"><label class="form-label" for="o2-tz">{{ __('Time zone') }}</label><input class="form-control" id="o2-tz" name="timezone" value="Asia/Karachi"></div>
        <div class="col-6"><label class="form-label" for="o2-cur">{{ __('Currency') }}</label><input class="form-control" id="o2-cur" name="currency" value="PKR" maxlength="3"></div>
        <div class="col-6"><label class="form-label" for="o2-ctry">{{ __('Phone country') }}</label><select class="form-select" id="o2-ctry" name="country"><option>PK</option><option>AE</option><option>SA</option><option>GB</option><option>US</option></select></div>
        <div class="col-12"><hr class="my-1"><div class="small-caps">{{ __('First administrator') }}</div></div>
        <div class="col-6"><label class="form-label" for="o2-an">{{ __('Name') }}</label><input class="form-control" id="o2-an" name="admin_name" required></div>
        <div class="col-6"><label class="form-label" for="o2-ae">{{ __('Email') }}</label><input class="form-control" type="email" id="o2-ae" name="admin_email" required></div>
        <div class="col-12"><label class="form-label" for="o2-ap">{{ __('Temporary password') }}</label><input class="form-control" type="password" id="o2-ap" name="admin_password" autocomplete="new-password" required></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button><button class="btn btn-primary" type="submit">{{ __('Create business') }}</button></div>
</form></div></div>
@endsection
@push('scripts')<script src="/js/pages/admin.js?v={{ config('app.asset_version') }}"></script>@endpush
