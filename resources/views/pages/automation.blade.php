@extends('layouts.app')
@section('title', 'Automation Rules')
@section('content')
<div class="lf-page-head">
    <h1>{{ __('Automation Rules') }}</h1>
    <div class="lf-actions"><button class="btn btn-primary btn-sm" id="newRule" type="button"><i class="bi bi-plus-lg"></i> {{ __('New rule') }}</button></div>
</div>
<p class="small text-muted mb-3">{{ __('When something happens (trigger), and the lead matches (conditions), do these things (actions). Each rule runs at most once per event; timed rules are checked every 5 minutes. Opt-out suppression and requested callbacks are built in and always enforced.') }}</p>

<div class="card mb-3">
    <div class="card-header">{{ __('Rules') }}</div>
    <div class="table-wrap"><table class="table align-middle table-dense mb-0">
        <thead><tr><th>{{ __('Active') }}</th><th>{{ __('Rule') }}</th><th>{{ __('When') }}</th><th>{{ __('Then') }}</th><th class="text-end">{{ __('Runs') }}</th><th class="text-end">{{ __('Errors') }}</th><th>{{ __('Last run') }}</th><th></th></tr></thead>
        <tbody id="ruleBody"></tbody>
    </table></div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center gap-2 flex-wrap">{{ __('Execution history') }}
        <select class="form-select form-select-sm w-auto ms-auto" id="exRule" aria-label="{{ __('Rule') }}"><option value="">{{ __('All rules') }}</option></select>
        <select class="form-select form-select-sm w-auto" id="exStatus" aria-label="{{ __('Status') }}"><option value="">{{ __('Any result') }}</option><option value="success">{{ __('Success') }}</option><option value="partial">{{ __('Partial') }}</option><option value="failed">{{ __('Failed') }}</option><option value="skipped">{{ __('Skipped') }}</option></select>
        <button class="btn btn-light btn-sm" id="exReload" type="button" aria-label="{{ __('Refresh') }}"><i class="bi bi-arrow-clockwise"></i></button>
    </div>
    <div class="table-wrap"><table class="table align-middle table-dense mb-0">
        <thead><tr><th>{{ __('When') }}</th><th>{{ __('Rule') }}</th><th>{{ __('Lead') }}</th><th>{{ __('Result') }}</th><th>{{ __('Details') }}</th></tr></thead>
        <tbody id="exBody"></tbody>
    </table></div>
    <div class="card-footer bg-white" id="exPager"></div>
</div>

<div class="modal fade" id="ruleModal" tabindex="-1" aria-labelledby="ruleTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><form class="modal-content" id="ruleForm" novalidate>
    <div class="modal-header"><h2 class="modal-title fs-5" id="ruleTitle">{{ __('Rule') }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <div class="row g-2 mb-3">
            <div class="col-md-6"><label class="form-label" for="r-name">{{ __('Name') }}</label><input class="form-control" id="r-name" name="name" maxlength="150"></div>
            <div class="col-md-6"><label class="form-label" for="r-desc">{{ __('Description') }}</label><input class="form-control" id="r-desc" name="description" maxlength="500"></div>
        </div>
        <h3 class="fs-6 mb-2"><span class="badge text-bg-primary me-1">1</span>{{ __('When') }}</h3>
        <div class="row g-2 mb-3 align-items-end">
            <div class="col-md-6"><label class="form-label" for="r-trigger">{{ __('Trigger') }}</label><select class="form-select" id="r-trigger" name="trigger"></select></div>
            <div class="col-md-3" id="delayWrap"><label class="form-label" for="r-delay" id="r-delay-label">{{ __('Delay (minutes)') }}</label><input class="form-control" type="number" min="5" max="43200" id="r-delay" name="delay_minutes"></div>
        </div>
        <h3 class="fs-6 mb-2"><span class="badge text-bg-primary me-1">2</span>{{ __('If the lead matches') }}
            <select class="form-select form-select-sm d-inline-block w-auto ms-2" id="r-match" aria-label="{{ __('Match') }}"><option value="all">{{ __('all conditions') }}</option><option value="any">{{ __('any condition') }}</option></select></h3>
        <div id="condList" class="mb-2"></div>
        <button class="btn btn-light btn-sm mb-3" type="button" id="addCond"><i class="bi bi-plus"></i> {{ __('Add condition') }}</button>
        <div class="small text-muted mb-3" id="noCond">{{ __('No conditions: the rule applies to every lead for this trigger.') }}</div>
        <h3 class="fs-6 mb-2"><span class="badge text-bg-primary me-1">3</span>{{ __('Then') }}</h3>
        <div id="actList" class="mb-2"></div>
        <button class="btn btn-light btn-sm" type="button" id="addAct"><i class="bi bi-plus"></i> {{ __('Add action') }}</button>
        <div class="small text-muted mt-2" id="placeholders"></div>
        <div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" role="switch" id="r-active"><label class="form-check-label" for="r-active">{{ __('Rule is active') }}</label></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button><button class="btn btn-primary" type="submit">{{ __('Save rule') }}</button></div>
</form></div></div>

<div class="modal fade" id="testModal" tabindex="-1" aria-labelledby="testTitle" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="testTitle">{{ __('Test rule against a lead') }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <label class="form-label" for="testQ">{{ __('Find a lead') }}</label>
        <input type="search" class="form-control mb-2" id="testQ" placeholder="{{ __('Name, phone or lead ID') }}" autocomplete="off">
        <div id="testLeads" class="list-group mb-3"></div>
        <div id="testResult"></div>
    </div>
</div></div></div>
@endsection
@push('scripts')<script src="/js/pages/automation.js?v={{ config('app.asset_version') }}"></script>@endpush
