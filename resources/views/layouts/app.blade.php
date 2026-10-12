<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'LeadFlow CRM') · {{ auth()->user()->organization->name }}</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/app.css?v={{ config('app.asset_version') }}">
</head>
<body data-page="{{ $page ?? '' }}"
      data-tz="{{ auth()->user()->timezone ?: auth()->user()->organization->timezone }}"
      data-currency="{{ auth()->user()->organization->currency }}"
      data-country="{{ auth()->user()->organization->default_country }}"
      data-user-id="{{ auth()->id() }}"
      data-perms="{{ json_encode(auth()->user()->permissionKeys()) }}"
      @foreach(($bodyData ?? []) as $k => $v) data-{{ $k }}="{{ $v }}" @endforeach>
<a class="visually-hidden-focusable btn btn-primary position-absolute m-2" href="#main">Skip to content</a>
<div class="lf-shell">
    <aside class="lf-sidebar" id="sidebar" aria-label="Main navigation">
        <a class="lf-brand" href="/"><i class="bi bi-funnel-fill"></i> LeadFlow</a>
        <ul class="lf-nav">
            @foreach($nav as [$key, $label, $icon, $path, $perms, $phase])
                <li><a href="{{ $path }}" class="{{ $active === $key ? 'active' : '' }}" @if($active === $key) aria-current="page" @endif>
                    <i class="bi bi-{{ $icon }}"></i><span>{{ $label }}</span>
                    @if($phase)<span class="lf-phase" title="Planned for phase {{ $phase }}">P{{ $phase }}</span>@endif
                </a></li>
            @endforeach
        </ul>
        <div class="lf-sidebar-foot">{{ auth()->user()->organization->name }}</div>
    </aside>
    <div class="lf-backdrop" id="sidebarBackdrop"></div>
    <div class="lf-main">
        <header class="lf-topbar">
            <button class="btn btn-light d-lg-none" id="sidebarToggle" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false"><i class="bi bi-list"></i></button>
            @if(auth()->user()->hasAnyPermission('leads.view_all','leads.view_team','leads.view_own'))
            <div class="lf-search" role="search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" class="form-control form-control-sm" id="globalSearch" placeholder="Search leads by name, phone, email or #ID" autocomplete="off" aria-label="Search leads">
                <div class="lf-search-results" id="globalSearchResults" role="listbox"></div>
            </div>
            @endif
            <div class="ms-auto d-flex align-items-center gap-2">
                @if(auth()->user()->hasPermission('leads.create'))
                <button class="btn btn-primary btn-sm" data-action="new-lead"><i class="bi bi-plus-lg"></i><span class="d-none d-sm-inline"> New lead</span></button>
                @endif
                <div class="dropdown">
                    <button class="btn btn-light btn-sm position-relative" data-bs-toggle="dropdown" aria-label="Notifications"><i class="bi bi-bell"></i></button>
                    <div class="dropdown-menu dropdown-menu-end p-3 mw-220">
                        <div class="small-caps mb-1">Notifications</div>
                        <div class="small text-muted">In-app notifications arrive with automation in Phase 3. Overdue follow-ups are on your dashboard and in Calls &amp; Follow-ups.</div>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="btn btn-light btn-sm d-flex align-items-center gap-2" data-bs-toggle="dropdown" aria-label="Account menu">
                        <span class="lf-avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                        <span class="d-none d-md-inline">{{ auth()->user()->name }}</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small text-muted">{{ auth()->user()->email }}</span></li>
                        <li><a class="dropdown-item" href="/profile"><i class="bi bi-person me-2"></i>My profile &amp; access</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><form method="post" action="/logout">@csrf<button class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form></li>
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
<script src="/js/core.js?v={{ config('app.asset_version') }}"></script>
@stack('scripts')
</body>
</html>
