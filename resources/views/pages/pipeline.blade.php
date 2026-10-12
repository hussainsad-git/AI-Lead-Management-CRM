@extends('layouts.app')
@section('title', 'Sales Pipeline')
@section('content')
<div class="lf-page-head"><h1>Sales Pipeline</h1><div class="lf-actions">
<input type="search" class="form-control form-control-sm mw-220" id="p-q" placeholder="Filter cards" aria-label="Filter cards">
<select class="form-select form-select-sm w-auto" id="p-user" aria-label="Assignee"></select>
<select class="form-select form-select-sm w-auto" id="p-source" data-fill="sources" data-empty="All sources" aria-label="Source"></select>
<div class="form-check form-switch align-self-center mb-0"><input class="form-check-input" type="checkbox" id="p-closed"><label class="form-check-label small" for="p-closed">Show closed</label></div>
<a class="btn btn-light btn-sm" href="/leads"><i class="bi bi-table"></i> Table view</a></div></div>
<p class="small text-muted mb-2">Drag a card to move a lead between stages. Closing stages ask for a reason. Each column shows up to 40 cards by priority and recent activity.</p>
<div class="kanban" id="board" aria-live="polite"></div>
@endsection
@push('vendor-scripts')<script src="/vendor/sortable/Sortable.min.js"></script>@endpush
@push('scripts')<script src="/js/pages/pipeline.js?v={{ config('app.asset_version') }}"></script>@endpush
