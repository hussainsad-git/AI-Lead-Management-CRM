@extends('layouts.app')
@section('title', 'Campaigns')
@section('content')
<div class="lf-page-head"><h1>Campaigns</h1><div class="lf-actions"><button class="btn btn-primary btn-sm manage-only" id="newCampaign"><i class="bi bi-plus-lg"></i> New campaign</button></div></div>
<div class="lf-notice mb-3"><i class="bi bi-info-circle me-1"></i>Meta Ads sync is not connected yet (Phase 4). Campaigns and daily spend shown here are entered manually; cost metrics only appear where spend has been recorded.</div>
<div class="card"><div class="table-wrap"><table class="table table-hover align-middle"><thead><tr><th>Campaign</th><th>Platform</th><th>Status</th><th class="text-end">Leads</th><th class="text-end">Qualified</th><th class="text-end">Converted</th><th class="text-end">Recorded spend</th><th class="text-end">CPL</th><th>Source</th><th></th></tr></thead><tbody id="camp-body"></tbody></table></div></div>
<div class="modal fade" id="campModal" tabindex="-1" aria-labelledby="campModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" id="campForm" novalidate>
<div class="modal-header"><h2 class="modal-title fs-5" id="campModalTitle">Campaign</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body"><div class="row g-2">
<div class="col-12"><label class="form-label" for="cm-name">Name</label><input class="form-control" id="cm-name" name="name" maxlength="191" required></div>
<div class="col-6"><label class="form-label" for="cm-platform">Platform</label><select class="form-select" id="cm-platform" name="platform"><option value="facebook">Facebook</option><option value="instagram">Instagram</option><option value="meta">Meta (both)</option><option value="google">Google</option><option value="other">Other</option></select></div>
<div class="col-6"><label class="form-label" for="cm-status">Status</label><select class="form-select" id="cm-status" name="status"><option value="active">Active</option><option value="paused">Paused</option><option value="ended">Ended</option></select></div>
<div class="col-6"><label class="form-label" for="cm-ext">Meta campaign ID</label><input class="form-control" id="cm-ext" name="external_id" maxlength="64" placeholder="Optional"></div>
<div class="col-6 cm-new"><label class="form-label" for="cm-cur">Currency</label><input class="form-control" id="cm-cur" name="currency" maxlength="3"></div>
<div class="col-6"><label class="form-label" for="cm-start">Starts</label><input class="form-control" type="date" id="cm-start" name="starts_on"></div>
<div class="col-6"><label class="form-label" for="cm-end">Ends</label><input class="form-control" type="date" id="cm-end" name="ends_on"></div>
<div class="col-12"><label class="form-label" for="cm-obj">Objective</label><input class="form-control" id="cm-obj" name="objective" maxlength="60" placeholder="e.g. Messages (click-to-WhatsApp)"></div>
</div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div></form></div></div>
<div class="modal fade" id="spendModal" tabindex="-1" aria-labelledby="spendModalTitle" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><h2 class="modal-title fs-5" id="spendModalTitle">Daily spend</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body"><form class="row g-2 align-items-end mb-3 manage-only" id="spendForm" novalidate>
<div class="col-5"><label class="form-label" for="sp-date">Date</label><input class="form-control" type="date" id="sp-date" name="spend_date" required></div>
<div class="col-4"><label class="form-label" for="sp-amount">Amount (<span id="sp-cur"></span>)</label><input class="form-control" type="number" min="0" step="0.01" id="sp-amount" name="amount" required></div>
<div class="col-3"><button class="btn btn-primary w-100" type="submit">Save day</button></div>
<div class="col-12 form-text">Enter the amount shown in Ads Manager for that day. Saving the same date again replaces it.</div></form>
<div class="table-wrap"><table class="table table-sm"><thead><tr><th>Date</th><th class="text-end">Amount</th><th>Source</th><th></th></tr></thead><tbody id="spend-body"></tbody></table></div></div></div></div></div>
@endsection

@push('scripts')<script src="/js/pages/campaigns.js?v={{ config('app.asset_version') }}"></script>@endpush
