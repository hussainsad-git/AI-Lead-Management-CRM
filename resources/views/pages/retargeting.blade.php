@extends('layouts.app')
@section('title', 'Retargeting')
@section('content')
<div class="lf-page-head"><h1>{{ __('Retargeting') }}</h1><div class="lf-actions"><a class="btn btn-light btn-sm" href="/audiences"><i class="bi bi-diagram-3"></i> {{ __('Manage segments') }}</a></div></div>
<div id="metaBanner" class="mb-3"></div>
<div class="card mb-3">
    <div class="card-header">{{ __('Meta Custom Audiences') }}</div>
    <div class="table-wrap"><table class="table align-middle table-dense mb-0">
        <thead><tr><th>{{ __('Segment') }}</th><th>{{ __('Meta audience') }}</th><th>{{ __('Size (Meta estimate)') }}</th><th>{{ __('Delivery') }}</th><th>{{ __('Last sync') }}</th><th></th></tr></thead>
        <tbody id="audBody"></tbody>
    </table></div>
    <div class="card-footer small text-muted">{{ __('Sizes and delivery status come from Meta and update after Meta finishes matching (usually within an hour). An audience needs enough matched people before Meta will serve ads to it; the CRM cannot predict the match rate.') }}</div>
</div>
<div class="card">
    <div class="card-header d-flex align-items-center gap-2">{{ __('Sync & export history') }}<button class="btn btn-light btn-sm ms-auto" id="jobsReload" type="button" aria-label="{{ __('Refresh') }}"><i class="bi bi-arrow-clockwise"></i></button></div>
    <div class="table-wrap"><table class="table align-middle table-dense mb-0">
        <thead><tr><th>{{ __('When') }}</th><th>{{ __('Segment') }}</th><th>{{ __('Action') }}</th><th>{{ __('Result') }}</th><th class="text-end">{{ __('Members') }}</th><th class="text-end">{{ __('Eligible') }}</th><th class="text-end">{{ __('Sent') }}</th><th>{{ __('Left out') }}</th><th>{{ __('By') }}</th></tr></thead>
        <tbody id="jobBody"></tbody>
    </table></div>
    <div class="card-footer bg-white" id="jobPager"></div>
</div>
<div class="card mt-3"><div class="card-header">{{ __('Requirements & rules') }}</div><div class="card-body small">
    <ul class="mb-0 ps-3">
        <li>{{ __('Meta Marketing API connected with a token that has ads_management, and the Custom Audience terms accepted for the ad account in Ads Manager → Audiences.') }}</li>
        <li>{{ __('Only eligible people are uploaded: not opted out, consent policy met, not on an attached exclusion list, with a phone or email. Identifiers are SHA-256 hashed before upload; names are never sent.') }}</li>
        <li>{{ __('Each sync is a full replace, so anyone who opted out since the last sync is removed from the Meta audience.') }}</li>
        <li>{{ __('Exclusion lists are never uploaded; use them to subtract people from audiences. To exclude customers from acquisition ads, upload the "Converted customers" audience and exclude it in the ad set.') }}</li>
        <li>{{ __('You are responsible for having the right to use the customer data for advertising under Meta\'s Customer List Custom Audience terms and local law.') }}</li>
    </ul>
</div></div>
@endsection
@push('scripts')<script src="/js/pages/retargeting.js?v={{ config('app.asset_version') }}"></script>@endpush
