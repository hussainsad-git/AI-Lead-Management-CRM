@extends('layouts.app')
@section('title', 'Integrations')
@section('content')
<div class="lf-page-head"><h1>Integrations</h1></div>
<div class="lf-notice mb-3">LeadFlow works fully without integrations: manual entry, spreadsheet import, call tracking and follow-ups. Connections below are reported honestly — nothing is shown as connected until an authenticated API call succeeds.</div>
<div class="row g-3" id="intRoot"></div>
@endsection

@push('scripts')<script src="/js/pages/integrations.js?v={{ config('app.asset_version') }}"></script>@endpush
