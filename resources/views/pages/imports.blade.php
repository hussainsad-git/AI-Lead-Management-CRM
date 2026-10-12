@extends('layouts.app')
@section('title', 'Import / Export')
@section('content')
<div class="lf-page-head"><h1>Import / Export</h1></div>
<div class="import-steps" id="steps">
    <span class="step" data-step="1">1 · Upload</span><span class="step" data-step="2">2 · Map columns</span>
    <span class="step" data-step="3">3 · Review</span><span class="step" data-step="4">4 · Import</span>
</div>
<div id="importRoot"></div>

<div class="card mt-3 import-only"><div class="card-header">Import history</div><div class="table-wrap"><table class="table table-sm align-middle"><thead><tr><th>#</th><th>File</th><th>By</th><th>Status</th><th class="text-end">Rows</th><th class="text-end">Created</th><th class="text-end">Updated</th><th class="text-end">Skipped</th><th class="text-end">Errors</th><th>When</th><th></th></tr></thead><tbody id="historyBody"></tbody></table></div></div>

<div class="card mt-3 export-only"><div class="card-header">Export leads</div><div class="card-body">
    <p class="small text-muted">Exports include only leads you can access, are logged in the audit trail, and are protected against spreadsheet formula injection. For filtered exports use the Export menu on the Leads page.</p>
    <div class="d-flex gap-2"><button class="btn btn-light btn-sm" data-export-all="csv"><i class="bi bi-filetype-csv"></i> All leads (CSV)</button><button class="btn btn-light btn-sm" data-export-all="xlsx"><i class="bi bi-file-earmark-excel"></i> All leads (Excel)</button></div>
</div></div>
@endsection
@push('scripts')<script src="/js/pages/imports.js?v={{ config('app.asset_version') }}"></script>@endpush
