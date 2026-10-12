@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<div class="lf-page-head">
    <h1>Dashboard</h1>
    <span class="badge text-bg-light border" id="dashScope"></span>
    <div class="lf-actions">
        <select class="form-select form-select-sm w-auto" id="dashRange" aria-label="Date range">
            <option value="today">Today</option><option value="yesterday">Yesterday</option><option value="last_7_days">Last 7 days</option>
            <option value="last_30_days" selected>Last 30 days</option><option value="this_month">This month</option><option value="previous_month">Previous month</option>
            <option value="all_time">All time</option><option value="custom">Custom range…</option>
        </select>
        <div class="d-flex gap-1 hidden" id="dashCustom">
            <input type="date" class="form-control form-control-sm" id="dashFrom" aria-label="From">
            <input type="date" class="form-control form-control-sm" id="dashTo" aria-label="To">
            <button class="btn btn-sm btn-primary" id="dashApply">Apply</button>
        </div>
    </div>
</div>
<div id="dashBody">{!! '<div class="lf-loading"><span class="spinner-border spinner-border-sm me-2"></span>Loading dashboard…</div>' !!}</div>
@endsection
@push('vendor-scripts')<script src="/vendor/chartjs/chart.umd.min.js"></script>@endpush
@push('scripts')<script src="/js/pages/dashboard.js?v={{ config('app.asset_version') }}"></script>@endpush
