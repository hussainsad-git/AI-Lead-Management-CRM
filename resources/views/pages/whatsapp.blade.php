@extends('layouts.app')
@section('title', 'WhatsApp Inbox')
@section('content')
<div class="inbox" id="inbox">
    <section class="inbox-list" aria-label="{{ __('Conversations') }}">
        <div class="filters">
            <input type="search" class="form-control form-control-sm mb-2" id="convSearch" placeholder="{{ __('Search name, number or message') }}" aria-label="{{ __('Search conversations') }}">
            <div class="btn-group btn-group-sm w-100 flex-wrap" role="group" id="convViews">
                <button class="btn btn-light active" data-view="all">{{ __('Open') }}</button>
                <button class="btn btn-light" data-view="mine">{{ __('Mine') }} <span class="badge text-bg-light" data-c="mine"></span></button>
                <button class="btn btn-light" data-view="unread">{{ __('Unread') }} <span class="badge text-bg-success" data-c="unread"></span></button>
                <button class="btn btn-light" data-view="handoff">{{ __('Needs human') }} <span class="badge text-bg-danger" data-c="handoff"></span></button>
                <button class="btn btn-light" data-view="unassigned">{{ __('Unassigned') }}</button>
                <button class="btn btn-light" data-view="closed">{{ __('Closed') }}</button>
            </div>
        </div>
        <div class="inbox-items" id="convList"></div>
    </section>
    <section class="thread" id="thread" aria-label="{{ __('Conversation') }}">
        <div class="inbox-empty" id="threadEmpty"><i class="bi bi-whatsapp fs-1 mb-2"></i><div>{{ __('Select a conversation') }}</div>
            <div class="small mt-2 px-4 text-center" id="waStatusNote"></div></div>
    </section>
    <aside class="inbox-side" id="side" aria-label="{{ __('Lead details') }}"></aside>
</div>

<div class="modal fade" id="teachModal" tabindex="-1" aria-labelledby="teachTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" id="teachForm" novalidate>
    <div class="modal-header"><h2 class="modal-title fs-5" id="teachTitle">{{ __('Teach the assistant') }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <p class="small text-muted">{{ __('Saves an approved question and answer to the knowledge base. Future replies will use it.') }}</p>
        <label class="form-label" for="tq">{{ __('Customer question') }}</label><textarea class="form-control mb-2" id="tq" name="question" rows="2" maxlength="1000" required></textarea>
        <label class="form-label" for="ta">{{ __('Correct answer') }}</label><textarea class="form-control" id="ta" name="corrected_text" rows="4" maxlength="4000" required></textarea>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button><button class="btn btn-primary" type="submit">{{ __('Save to knowledge base') }}</button></div>
</form></div></div>
@endsection
@push('scripts')<script src="/js/pages/whatsapp.js?v={{ config('app.asset_version') }}"></script>@endpush
