@extends('layouts.app')
@section('title', 'My profile')
@section('content')
<div class="lf-page-head"><h1>{{ __('My profile') }}</h1></div>
<div class="row g-3">
    <div class="col-lg-6"><div class="card"><div class="card-header">{{ __('Personal details') }}</div><form class="card-body" id="profileForm" novalidate>
        <div class="row g-2">
            <div class="col-md-6"><label class="form-label" for="p-name">{{ __('Name') }}</label><input class="form-control" id="p-name" name="name" maxlength="120" required></div>
            <div class="col-md-6"><label class="form-label" for="p-email">{{ __('Email') }}</label><input class="form-control" id="p-email" disabled></div>
            <div class="col-md-6"><label class="form-label" for="p-phone">{{ __('Phone') }}</label><input class="form-control" id="p-phone" name="phone" maxlength="32"></div>
            <div class="col-md-6"><label class="form-label" for="p-locale">{{ __('Language') }}</label><select class="form-select" id="p-locale" name="locale"></select></div>
            <div class="col-12"><label class="form-label" for="p-tz">{{ __('Time zone') }}</label><select class="form-select" id="p-tz" name="timezone"></select><div class="form-text">{{ __('Leave on "organization default" unless you work from another time zone.') }}</div></div>
            <div class="col-12 small text-muted" id="p-roles"></div>
        </div>
        <button class="btn btn-primary mt-3" type="submit">{{ __('Save') }}</button>
    </form></div></div>
    <div class="col-lg-6"><div class="card"><div class="card-header">{{ __('Change password') }}</div><form class="card-body" id="pwForm" novalidate autocomplete="off">
        <div class="mb-2"><label class="form-label" for="pw-cur">{{ __('Current password') }}</label><input class="form-control" type="password" id="pw-cur" name="current_password" autocomplete="current-password" required></div>
        <div class="mb-2"><label class="form-label" for="pw-new">{{ __('New password') }}</label><input class="form-control" type="password" id="pw-new" name="password" autocomplete="new-password" required><div class="form-text">{{ __('At least 10 characters with letters and numbers. Other devices will be signed out.') }}</div></div>
        <div class="mb-2"><label class="form-label" for="pw-conf">{{ __('Confirm new password') }}</label><input class="form-control" type="password" id="pw-conf" name="password_confirmation" autocomplete="new-password" required></div>
        <button class="btn btn-primary" type="submit">{{ __('Change password') }}</button>
    </form></div>
    <div class="card mt-3"><div class="card-header">{{ __('Email notifications') }}</div><form class="card-body" id="notifForm">
        <div class="alert alert-warning small d-none" id="mailOff">{{ __('Email delivery is not configured on this server (MAIL_MAILER). Your choices are saved and apply once an administrator configures mail.') }}</div>
        <div class="small text-muted mb-2">{{ __('In-app notifications are always on. Choose which ones are also emailed to you.') }}</div>
        <div id="notifTypes"></div>
        <button class="btn btn-outline-primary btn-sm mt-2" type="submit">{{ __('Save preferences') }}</button>
    </form></div>
    <div class="card mt-3"><div class="card-header">{{ __('What you can do') }}</div><div class="card-body">
        @foreach(collect(config('leadflow.permissions'))->filter(fn ($v, $k) => auth()->user()->hasPermission($k)) as $key => [$group, $desc])
            <div class="small"><i class="bi bi-check2 text-success me-1"></i>{{ __($desc) }}</div>
        @endforeach
    </div></div></div>
</div>
@endsection
@push('scripts')<script src="/js/pages/profile.js?v={{ config('app.asset_version') }}"></script>@endpush
