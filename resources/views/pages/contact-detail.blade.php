@extends('layouts.app')
@section('title', 'Contact')
@section('content')
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2"><li class="breadcrumb-item"><a href="/contacts">Contacts</a></li><li class="breadcrumb-item active" id="crumb" aria-current="page">Contact</li></ol></nav><div id="contactRoot"><div class="lf-loading"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div></div>
@endsection

@push('scripts')<script src="/js/pages/contact-detail.js?v={{ config('app.asset_version') }}"></script>@endpush
