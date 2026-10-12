@extends('layouts.app')
@section('title', 'Calendar & Appointments')
@section('content')
<div class="lf-page-head"><h1>Calendar &amp; Appointments</h1><div class="lf-actions">
<select class="form-select form-select-sm w-auto" id="cal-user" aria-label="Assignee"></select>
<div class="btn-group btn-group-sm"><button class="btn btn-light" id="cal-prev" aria-label="Previous month"><i class="bi bi-chevron-left"></i></button><button class="btn btn-light" id="cal-today">Today</button><button class="btn btn-light" id="cal-next" aria-label="Next month"><i class="bi bi-chevron-right"></i></button></div></div></div>
<h2 class="h6 mb-2" id="cal-title"></h2>
<div class="card mb-3 cal-grid-wrap"><div class="cal-grid" id="cal-head"></div><div class="cal-grid" id="cal-grid"></div></div>
<div class="card cal-list-mobile"><ul class="list-group list-group-flush" id="cal-list"></ul></div>
<p class="small text-muted mt-2">Appointments are created from a lead (Lead → Appointment). Click an appointment to update its outcome.</p>
@endsection

@push('scripts')<script src="/js/pages/calendar.js?v={{ config('app.asset_version') }}"></script>@endpush
