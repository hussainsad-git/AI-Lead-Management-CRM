@extends('layouts.app')
@section('title', 'Analytics & Reports')
@section('content')
<div class="lf-page-head">
    <h1>{{ __('Analytics & Reports') }}</h1>
    <div class="lf-actions">
        <select class="form-select form-select-sm w-auto" id="anRange" aria-label="{{ __('Date range') }}">
            <option value="today">{{ __('Today') }}</option><option value="yesterday">{{ __('Yesterday') }}</option><option value="last_7_days">{{ __('Last 7 days') }}</option>
            <option value="last_30_days" selected>{{ __('Last 30 days') }}</option><option value="this_month">{{ __('This month') }}</option><option value="previous_month">{{ __('Previous month') }}</option>
            <option value="all_time">{{ __('All time') }}</option><option value="custom">{{ __('Custom range…') }}</option></select>
        <input type="date" class="form-control form-control-sm w-auto hidden" id="anFrom" aria-label="{{ __('From') }}"><input type="date" class="form-control form-control-sm w-auto hidden" id="anTo" aria-label="{{ __('To') }}">
        <select class="form-select form-select-sm w-auto" id="anSource" data-fill="sources" data-empty="{{ __('All sources') }}" aria-label="{{ __('Source') }}"></select>
        <select class="form-select form-select-sm w-auto" id="anUser" data-fill="users" data-empty="{{ __('All salespeople') }}" aria-label="{{ __('Salesperson') }}"></select>
        <select class="form-select form-select-sm w-auto" id="anCampaign" data-fill="campaigns" data-empty="{{ __('All campaigns') }}" aria-label="{{ __('Campaign') }}"></select>
        <select class="form-select form-select-sm w-auto" id="anPlatform" aria-label="{{ __('Platform') }}"><option value="">{{ __('All platforms') }}</option><option value="facebook">Facebook</option><option value="instagram">Instagram</option><option value="meta">Meta</option></select>
    </div>
</div>
<div id="anBody"><div class="lf-loading"><span class="spinner-border spinner-border-sm me-2"></span>{{ __('Loading…') }}</div></div>
@endsection
@push('vendor-scripts')<script src="/vendor/chartjs/chart.umd.min.js"></script>@endpush
@push('scripts')<script src="/js/pages/analytics.js?v={{ config('app.asset_version') }}"></script>@endpush
