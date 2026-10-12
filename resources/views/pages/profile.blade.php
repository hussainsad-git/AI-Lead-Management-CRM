@extends('layouts.app')
@section('title', 'My profile')
@section('content')
<div class="lf-page-head"><h1>My profile &amp; access</h1></div>
<div class="row g-3">
    <div class="col-lg-5"><div class="card"><div class="card-body">
        <dl class="dl-grid">
            <dt>Name</dt><dd>{{ auth()->user()->name }}</dd>
            <dt>Email</dt><dd>{{ auth()->user()->email }}</dd>
            <dt>Organization</dt><dd>{{ auth()->user()->organization->name }}</dd>
            <dt>Team</dt><dd>{{ auth()->user()->team?->name ?? '—' }}</dd>
            <dt>Roles</dt><dd>{{ auth()->user()->roles->pluck('name')->implode(', ') }}</dd>
            <dt>Time zone</dt><dd>{{ auth()->user()->timezone ?: auth()->user()->organization->timezone }}</dd>
        </dl>
        <p class="small text-muted mt-3 mb-0">Ask an administrator to change your role, team or password.</p>
    </div></div></div>
    <div class="col-lg-7"><div class="card"><div class="card-header">What you can do</div><div class="card-body">
        @foreach(collect(config('leadflow.permissions'))->filter(fn ($v, $k) => auth()->user()->hasPermission($k)) as $key => [$group, $desc])
            <div class="small"><i class="bi bi-check2 text-success me-1"></i>{{ $desc }}</div>
        @endforeach
    </div></div></div>
</div>
@endsection
