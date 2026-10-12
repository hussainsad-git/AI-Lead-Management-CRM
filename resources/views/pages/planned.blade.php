@extends('layouts.app')
@section('title', $item[1])
@section('content')
<div class="lf-page-head"><h1>{{ $item[1] }}</h1><span class="badge text-bg-light border">Planned · Phase {{ $item[5] }}</span></div>
<div class="card"><div class="card-body">
    <div class="lf-empty"><i class="bi bi-{{ $item[2] }}"></i>
        <p class="mb-1 fw-semibold text-body">This module is not built yet.</p>
        <p class="mb-0">It is scheduled for implementation phase {{ $item[5] }}. No sample or placeholder data is shown.</p>
    </div>
    @if($item[0] === 'whatsapp')
        <p class="small text-muted mb-0">Requires the official WhatsApp Business Platform (Cloud API) connection — see Integrations for prerequisites. LeadFlow will never automate a personal WhatsApp Web session.</p>
    @elseif(in_array($item[0], ['audiences', 'retargeting']))
        <p class="small text-muted mb-0">Segments will be built from CRM data with opt-out exclusions applied before any export or Meta Custom Audience upload. Matching rates and audience sizes cannot be guaranteed.</p>
    @elseif($item[0] === 'reports')
        <p class="small text-muted mb-0">Until then, the Dashboard provides cohort metrics, funnel, campaign cost per lead (where spend is recorded) and salesperson performance.</p>
    @elseif($item[0] === 'automation')
        <p class="small text-muted mb-0">Planned rules: auto-assignment, first-contact SLA reminders, callback tasks, escalation and opt-out enforcement — idempotent and loop-protected. Missed follow-ups are already marked hourly by the scheduler.</p>
    @endif
</div></div>
@endsection
