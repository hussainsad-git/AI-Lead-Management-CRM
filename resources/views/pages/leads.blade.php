@extends('layouts.app')
@section('title', 'Leads')
@section('content')
<div class="lf-page-head">
    <h1>Leads</h1>
    <div class="lf-actions">
        <div class="dropdown">
            <button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown" id="viewsBtn"><i class="bi bi-bookmark"></i> Views</button>
            <div class="dropdown-menu dropdown-menu-end p-2 mw-220" id="viewsMenu"></div>
        </div>
        <div class="dropdown">
            <button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside"><i class="bi bi-layout-three-columns"></i> Columns</button>
            <div class="dropdown-menu dropdown-menu-end p-2 columns-menu" id="columnsMenu"></div>
        </div>
        <div class="dropdown export-only">
            <button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-download"></i> Export</button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><button class="dropdown-item" data-export="csv">Filtered leads — CSV</button></li>
                <li><button class="dropdown-item" data-export="xlsx">Filtered leads — Excel</button></li>
            </ul>
        </div>
        <button class="btn btn-primary btn-sm create-only" data-action="new-lead"><i class="bi bi-plus-lg"></i> New lead</button>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form id="filters" class="row g-2 align-items-end" autocomplete="off">
            <div class="col-12 col-md-4 col-xl-3"><label class="form-label small mb-0" for="f-q">Search</label><input type="search" class="form-control form-control-sm" id="f-q" name="q" placeholder="Name, phone, email, #ID, notes"></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-0" for="f-status">Status</label><select class="form-select form-select-sm" id="f-status" name="status"></select></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-0" for="f-source">Source</label><select class="form-select form-select-sm" id="f-source" name="source_ids" data-fill="sources" data-empty="All sources"></select></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-0" for="f-user">Assigned</label><select class="form-select form-select-sm" id="f-user" name="assigned_user_ids"></select></div>
            <div class="col-6 col-md-2 col-xl-1"><label class="form-label small mb-0" for="f-priority">Priority</label><select class="form-select form-select-sm" id="f-priority" name="priority"><option value="">Any</option><option value="urgent">Urgent</option><option value="high">High</option><option value="normal">Normal</option><option value="low">Low</option></select></div>
            <div class="col-6 col-md-2 col-xl-1"><label class="form-label small mb-0" for="f-follow">Follow-up</label><select class="form-select form-select-sm" id="f-follow" name="follow_up"><option value="">Any</option><option value="overdue">Overdue</option><option value="today">Today</option><option value="upcoming">Upcoming</option><option value="none">None set</option></select></div>
            <div class="col-6 col-md-auto"><button class="btn btn-sm btn-light w-100" type="button" data-bs-toggle="collapse" data-bs-target="#moreFilters" aria-expanded="false"><i class="bi bi-sliders"></i> More</button></div>
            <div class="col-6 col-md-auto"><button class="btn btn-sm btn-link text-decoration-none" type="button" id="resetFilters">Reset</button></div>
            <div class="collapse col-12" id="moreFilters">
                <div class="row g-2 pt-1">
                    <div class="col-6 col-md-3 col-xl-2"><label class="form-label small mb-0" for="f-campaign">Campaign</label><select class="form-select form-select-sm" id="f-campaign" name="campaign_ids" data-fill="campaigns" data-empty="Any campaign"></select></div>
                    <div class="col-6 col-md-3 col-xl-2"><label class="form-label small mb-0" for="f-from">Created from</label><input type="date" class="form-control form-control-sm" id="f-from" name="created_from"></div>
                    <div class="col-6 col-md-3 col-xl-2"><label class="form-label small mb-0" for="f-to">Created to</label><input type="date" class="form-control form-control-sm" id="f-to" name="created_to"></div>
                    <div class="col-6 col-md-3 col-xl-1"><label class="form-label small mb-0" for="f-contacted">Contacted</label><select class="form-select form-select-sm" id="f-contacted" name="contacted"><option value="">Any</option><option value="yes">Yes</option><option value="no">Never</option></select></div>
                    <div class="col-6 col-md-3 col-xl-1"><label class="form-label small mb-0" for="f-responded">Responded</label><select class="form-select form-select-sm" id="f-responded" name="responded"><option value="">Any</option><option value="yes">Yes</option><option value="no">No</option></select></div>
                    <div class="col-3 col-md-2 col-xl-1"><label class="form-label small mb-0" for="f-amin">Attempts ≥</label><input type="number" min="0" class="form-control form-control-sm" id="f-amin" name="attempts_min"></div>
                    <div class="col-3 col-md-2 col-xl-1"><label class="form-label small mb-0" for="f-amax">Attempts ≤</label><input type="number" min="0" class="form-control form-control-sm" id="f-amax" name="attempts_max"></div>
                    <div class="col-6 col-md-3 col-xl-1"><label class="form-label small mb-0" for="f-optout">Consent</label><select class="form-select form-select-sm" id="f-optout" name="opt_out"><option value="">Any</option><option value="contactable">Contactable</option><option value="opted_out">Opted out</option></select></div>
                    <div class="col-6 col-md-3 col-xl-1"><label class="form-label small mb-0" for="f-score">Score</label><select class="form-select form-select-sm" id="f-score" name="score_label"><option value="">Any</option><option value="hot">Hot</option><option value="warm">Warm</option><option value="cold">Cold</option><option value="needs_review">Needs review</option></select></div>
                    <div class="col-6 col-md-3 col-xl-1"><label class="form-label small mb-0" for="f-tag">Tag</label><select class="form-select form-select-sm" id="f-tag" name="tag_ids" data-fill="tags" data-empty="Any"></select></div>
                    <div class="col-6 col-md-3 col-xl-1"><label class="form-label small mb-0" for="f-arch">Archived</label><select class="form-select form-select-sm" id="f-arch" name="archived"><option value="exclude">Hide</option><option value="include">Include</option><option value="only">Only</option></select></div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="lf-bulkbar" id="bulkBar" role="region" aria-label="Bulk actions">
    <strong id="bulkCount">0 selected</strong>
    <button class="btn btn-sm btn-link p-0" id="selectAllMatching" type="button"></button>
    <div class="vr"></div>
    <button class="btn btn-sm btn-light" data-bulk="assign"><i class="bi bi-person-check"></i> Assign</button>
    <button class="btn btn-sm btn-light" data-bulk="status"><i class="bi bi-flag"></i> Status</button>
    <button class="btn btn-sm btn-light" data-bulk="priority"><i class="bi bi-exclamation-diamond"></i> Priority</button>
    <button class="btn btn-sm btn-light" data-bulk="follow_up"><i class="bi bi-telephone-plus"></i> Follow-up</button>
    <button class="btn btn-sm btn-light" data-bulk="tags_add"><i class="bi bi-tags"></i> Add tags</button>
    <button class="btn btn-sm btn-light" data-bulk="export"><i class="bi bi-download"></i> Export</button>
    <button class="btn btn-sm btn-outline-danger" data-bulk="archive"><i class="bi bi-archive"></i> Archive</button>
    <button class="btn btn-sm btn-link ms-auto" id="clearSelection" type="button">Clear</button>
</div>

<div class="card">
    <div class="table-wrap"><table class="table table-hover align-middle table-dense" id="leadsTable"><thead></thead><tbody></tbody></table></div>
    <div class="card-footer bg-white" id="leadsPager"></div>
</div>

<div class="modal fade" id="bulkModal" tabindex="-1" aria-labelledby="bulkModalTitle" aria-hidden="true">
    <div class="modal-dialog"><form class="modal-content" id="bulkForm" novalidate>
        <div class="modal-header"><h2 class="modal-title fs-5" id="bulkModalTitle">Bulk update</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body" id="bulkBody"></div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Apply</button></div>
    </form></div>
</div>
@endsection
@push('scripts')<script src="/js/pages/leads.js?v={{ config('app.asset_version') }}"></script>@endpush
