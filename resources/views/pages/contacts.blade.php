@extends('layouts.app')
@section('title', 'Contacts')
@section('content')
<div class="lf-page-head"><h1>Contacts</h1><div class="lf-actions"><input type="search" class="form-control form-control-sm" id="c-q" placeholder="Search name, phone, email" aria-label="Search contacts"><select class="form-select form-select-sm w-auto" id="c-opt" aria-label="Consent filter"><option value="">All</option><option value="contactable">Contactable</option><option value="opted_out">Opted out</option></select></div></div>
<p class="small text-muted">A contact is a person or business. One contact can have several inquiries (leads). Contacts appear here when you can see at least one of their leads.</p>
<div class="card"><div class="table-wrap"><table class="table table-hover"><thead><tr><th>Name</th><th>Phone</th><th>WhatsApp</th><th>Email</th><th>City</th><th class="text-end">Leads</th><th>Created</th></tr></thead><tbody id="c-body"></tbody></table></div><div class="card-footer bg-white" id="c-pager"></div></div>
@endsection

@push('scripts')<script src="/js/pages/contacts.js?v={{ config('app.asset_version') }}"></script>@endpush
