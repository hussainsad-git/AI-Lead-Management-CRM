@extends('layouts.app')
@section('title', 'Integrations')
@section('content')
<div class="lf-page-head"><h1>{{ __('Integrations') }}</h1></div>
<div class="lf-notice mb-3">{{ __('Credentials are stored encrypted on the server and are never shown again after saving. A connection shows "Connected" only after a successful authenticated test call.') }}</div>
<div class="row g-3" id="intRoot"></div>
@endsection
@push('scripts')<script src="/js/pages/integrations.js?v={{ config('app.asset_version') }}"></script>@endpush
