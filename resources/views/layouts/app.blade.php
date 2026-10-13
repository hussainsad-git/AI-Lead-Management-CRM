@php
    $u = auth()->user();
    $org = $u->organization;
    $loc = config('locales.'.app()->getLocale()) ?? config('locales.en');
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $loc['rtl'] ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __(trim($__env->yieldContent('title', 'LeadFlow CRM'))) }} · {{ $org->name }}</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="/vendor/bootstrap/{{ $loc['rtl'] ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css' }}">
    <link rel="stylesheet" href="/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/app.css?v={{ config('app.asset_version') }}">
    @if($org->brand_color)<link rel="stylesheet" href="/branding/theme.css?c={{ ltrim($org->brand_color, '#') }}">@endif
</head>
<body data-page="{{ $page ?? '' }}"
      data-tz="{{ $u->timezone ?: $org->timezone }}"
      data-currency="{{ $org->currency }}"
      data-country="{{ $org->default_country }}"
      data-locale="{{ app()->getLocale() }}"
      data-user-id="{{ $u->id }}"
      data-perms="{{ json_encode($u->permissionKeys()) }}"
      @foreach(($bodyData ?? []) as $k => $v) data-{{ $k }}="{{ $v }}" @endforeach>
<a class="visually-hidden-focusable btn btn-primary position-absolute m-2" href="#main">{{ __('Skip to content') }}</a>
<div class="lf-shell">
    <aside class="lf-sidebar" id="sidebar" aria-label="{{ __('Main navigation') }}">
        <a class="lf-brand" href="/">
            @if($org->logo_path)<img src="/branding/logo" alt="" class="lf-logo">@else<i class="bi bi-funnel-fill"></i>@endif
            <span>{{ $org->logo_path ? $org->name : 'LeadFlow' }}</span>
        </a>
        <ul class="lf-nav">
            @foreach($nav as [$key, $label, $icon, $path, $perms, $phase])
                <li><a href="{{ $path }}" class="{{ $active === $key ? 'active' : '' }}" @if($active === $key) aria-current="page" @endif>
                    <i class="bi bi-{{ $icon }}"></i><span>{{ __($label) }}</span>
                    @if($phase)<span class="lf-phase" title="{{ __('Planned') }} · P{{ $phase }}">P{{ $phase }}</span>@endif
                </a></li>
            @endforeach
            @if($u->is_platform_admin)
                <li class="mt-2"><a href="/admin" class="{{ $active === 'admin' ? 'active' : '' }}"><i class="bi bi-buildings"></i><span>{{ __('Platform Admin') }}</span></a></li>
            @endif
        </ul>
        <div class="lf-sidebar-foot">{{ $org->name }}</div>
    </aside>
    <div class="lf-backdrop" id="sidebarBackdrop"></div>
    <div class="lf-main">
        <header class="lf-topbar">
            <button class="btn btn-light d-lg-none" id="sidebarToggle" aria-label="{{ __('Open navigation') }}" aria-controls="sidebar" aria-expanded="false"><i class="bi bi-list"></i></button>
            @if($u->hasAnyPermission('leads.view_all','leads.view_team','leads.view_own'))
            <div class="lf-search" role="search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" class="form-control form-control-sm" id="globalSearch" placeholder="{{ __('Search leads by name, phone, email or #ID') }}" autocomplete="off" aria-label="{{ __('Search leads') }}">
                <div class="lf-search-results" id="globalSearchResults" role="listbox"></div>
            </div>
            @endif
            <div class="ms-auto d-flex align-items-center gap-2">
                @if($u->hasPermission('leads.create'))
                <button class="btn btn-primary btn-sm" data-action="new-lead"><i class="bi bi-plus-lg"></i><span class="d-none d-sm-inline"> {{ __('New lead') }}</span></button>
                @endif
                <div class="dropdown">
                    <button class="btn btn-light btn-sm position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="{{ __('Notifications') }}" id="notifBtn">
                        <i class="bi bi-bell"></i><span class="lf-badge hidden" id="notifCount"></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end p-0 lf-notif-menu">
                        <div class="d-flex align-items-center px-3 py-2 border-bottom"><strong class="small">{{ __('Notifications') }}</strong>
                            <button class="btn btn-link btn-sm ms-auto p-0" id="notifReadAll">{{ __('Mark all read') }}</button></div>
                        <div id="notifList"><div class="p-3 small text-muted">{{ __('Loading…') }}</div></div>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="btn btn-light btn-sm d-flex align-items-center gap-2" data-bs-toggle="dropdown" aria-label="{{ __('Account menu') }}">
                        <span class="lf-avatar">{{ mb_strtoupper(mb_substr($u->name, 0, 1)) }}</span>
                        <span class="d-none d-md-inline">{{ $u->name }}</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small text-muted">{{ $u->email }}</span></li>
                        <li><a class="dropdown-item" href="/profile"><i class="bi bi-person me-2"></i>{{ __('My profile') }}</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><span class="dropdown-item-text small-caps">{{ __('Language') }}</span></li>
                        @foreach(config('locales') as $code => $l)
                            <li><form method="post" action="/locale">@csrf<input type="hidden" name="locale" value="{{ $code }}">
                                <button class="dropdown-item {{ app()->getLocale() === $code ? 'active' : '' }}" lang="{{ $code }}">{{ $l['native'] }}</button></form></li>
                        @endforeach
                        <li><hr class="dropdown-divider"></li>
                        <li><form method="post" action="/logout">@csrf<button class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>{{ __('Sign out') }}</button></form></li>
                    </ul>
                </div>
            </div>
        </header>
        <main class="lf-content" id="main">
            @yield('content')
        </main>
    </div>
</div>
@include('partials.modals')
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts" aria-live="polite"></div>
<script src="/vendor/jquery/jquery.min.js"></script>
<script src="/vendor/bootstrap/bootstrap.bundle.min.js"></script>
@stack('vendor-scripts')
<script src="/js/i18n/{{ app()->getLocale() }}.js?v={{ config('app.asset_version') }}"></script>
<script src="/js/core.js?v={{ config('app.asset_version') }}"></script>
@stack('scripts')
</body>
</html>
