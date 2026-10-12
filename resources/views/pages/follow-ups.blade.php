@extends('layouts.app')
@section('title', 'Calls & Follow-ups')
@section('content')
<div class="lf-page-head"><h1>Calls &amp; Follow-ups</h1><div class="lf-actions">
<select class="form-select form-select-sm w-auto" id="fu-filter-user" aria-label="Assignee"></select>
<select class="form-select form-select-sm w-auto" id="fu-filter-prio" aria-label="Priority"><option value="">Any priority</option><option value="urgent">Urgent</option><option value="high">High</option><option value="normal">Normal</option><option value="low">Low</option></select></div></div>
<ul class="nav nav-pills mb-3 gap-1" id="fuTabs" role="tablist">
<li class="nav-item"><button class="nav-link" data-view="overdue" role="tab">Overdue <span class="badge text-bg-danger" data-count="overdue"></span></button></li>
<li class="nav-item"><button class="nav-link" data-view="today" role="tab">Today <span class="badge text-bg-light" data-count="today"></span></button></li>
<li class="nav-item"><button class="nav-link" data-view="upcoming" role="tab">Upcoming <span class="badge text-bg-light" data-count="upcoming"></span></button></li>
<li class="nav-item"><button class="nav-link" data-view="missed" role="tab">Missed <span class="badge text-bg-light" data-count="missed"></span></button></li>
<li class="nav-item"><button class="nav-link" data-view="completed" role="tab">Completed</button></li>
<li class="nav-item"><button class="nav-link" data-view="cancelled" role="tab">Cancelled</button></li></ul>
<div class="card"><div class="table-wrap"><table class="table table-hover align-middle"><thead><tr><th>Lead</th><th>Phone</th><th>When</th><th>Purpose</th><th>Priority</th><th>Assignee</th><th>Status</th><th></th></tr></thead><tbody id="fu-body"></tbody></table></div><div class="card-footer bg-white" id="fu-pager"></div></div>
<p class="small text-muted mt-2">“Missed” = still not done more than a day after the scheduled time. Call duration is only shown when a user enters it; LeadFlow does not record calls.</p>
@endsection

@push('scripts')<script src="/js/pages/follow-ups.js?v={{ config('app.asset_version') }}"></script>@endpush
