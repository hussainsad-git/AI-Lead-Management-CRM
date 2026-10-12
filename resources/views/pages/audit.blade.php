@extends('layouts.app')
@section('title', 'Audit Logs')
@section('content')
<div class="lf-page-head"><h1>Audit Logs</h1><div class="lf-actions"><select class="form-select form-select-sm w-auto" id="a-action" aria-label="Action filter"><option value="">All actions</option><option value="auth.">Sign-ins</option><option value="leads.">Exports &amp; bulk actions</option><option value="lead.">Lead archive</option><option value="contact.">Merges &amp; opt-outs</option><option value="user.">Users</option><option value="role.">Role permissions</option><option value="settings.">Settings</option><option value="import.">Imports</option><option value="campaign.">Campaigns &amp; spend</option></select></div></div>
<div class="card"><div class="table-wrap"><table class="table align-middle"><thead><tr><th>When</th><th>User</th><th>Action</th><th>Subject</th><th>Details</th><th>IP</th></tr></thead><tbody id="a-body"></tbody></table></div><div class="card-footer bg-white" id="a-pager"></div></div>
@endsection

@push('scripts')<script src="/js/pages/audit.js?v={{ config('app.asset_version') }}"></script>@endpush
