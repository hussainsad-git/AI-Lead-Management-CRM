@extends('layouts.app')
@section('title', 'Integrations')
@section('content')
<div class="lf-page-head"><h1>{{ __('Integrations') }}</h1></div>
<div class="lf-notice mb-3">{{ __('Credentials are stored encrypted on the server and are never shown again after saving. A connection shows "Connected" only after a successful authenticated test call.') }}</div>
<div class="row g-3" id="intRoot"></div>

<div class="card mt-4" id="webFormsCard">
  <div class="card-body">
    <div class="d-flex align-items-start mb-2">
      <h2 class="h6 mb-0"><i class="bi bi-globe me-1"></i>{{ __('Website & landing-page forms') }}</h2>
      <button class="btn btn-primary btn-sm ms-auto" type="button" data-action="new-web-form"><i class="bi bi-plus-lg"></i> {{ __('New form endpoint') }}</button>
    </div>
    <p class="small text-muted mb-3">{{ __('Each endpoint accepts POSTs from your own site (plain HTML form, fetch, or a page builder webhook) and creates a lead with UTM/fbclid attribution. The key only allows creating leads for this business; restrict it to your site origins and rotate it if it leaks.') }}</p>
    <div id="webFormsList"></div>
  </div>
</div>

<div class="modal fade" id="webFormModal" tabindex="-1" aria-labelledby="webFormModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form id="webFormForm" novalidate autocomplete="off">
      <div class="modal-header"><h2 class="modal-title h6" id="webFormModalTitle">{{ __('Form endpoint') }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button></div>
      <div class="modal-body">
        <input type="hidden" name="id">
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label small" for="wf-name">{{ __('Name') }}</label><input class="form-control form-control-sm" id="wf-name" name="name" required maxlength="120"></div>
          <div class="col-md-6"><label class="form-label small" for="wf-source">{{ __('Lead source') }}</label><select class="form-select form-select-sm" id="wf-source" name="lead_source_id" required></select></div>
          <div class="col-md-6"><label class="form-label small" for="wf-assignee">{{ __('Assign new leads to') }}</label><select class="form-select form-select-sm" id="wf-assignee" name="assigned_user_id"><option value="">{{ __('Automation rules / unassigned') }}</option></select></div>
          <div class="col-md-6"><label class="form-label small" for="wf-campaign">{{ __('Attribute to campaign') }}</label><select class="form-select form-select-sm" id="wf-campaign" name="campaign_id"><option value="">{{ __('From utm_campaign if present') }}</option></select></div>
          <div class="col-md-6"><label class="form-label small" for="wf-origins">{{ __('Allowed origins (one per line)') }}</label><textarea class="form-control form-control-sm" id="wf-origins" name="allowed_origins_text" rows="3" placeholder="https://www.example.com"></textarea><div class="form-text">{{ __('Scheme and host only. Leave empty to accept any site (not recommended).') }}</div></div>
          <div class="col-md-6"><label class="form-label small" for="wf-redirect">{{ __('Thank-you page (plain HTML forms)') }}</label><input class="form-control form-control-sm" id="wf-redirect" name="redirect_url" type="url" placeholder="https://www.example.com/thanks">
            <label class="form-label small mt-2" for="wf-tags">{{ __('Tags (comma separated)') }}</label><input class="form-control form-control-sm" id="wf-tags" name="tags_text" placeholder="web, contact-page"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">{{ __('Cancel') }}</button><button type="submit" class="btn btn-primary btn-sm">{{ __('Save') }}</button></div>
    </form>
  </div></div>
</div>

<div class="modal fade" id="webFormEmbedModal" tabindex="-1" aria-labelledby="webFormEmbedTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h6" id="webFormEmbedTitle">{{ __('Embed') }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button></div>
    <div class="modal-body">
      <div class="input-group input-group-sm mb-2"><span class="input-group-text">{{ __('Endpoint') }}</span><input class="form-control" readonly id="wfEndpoint" aria-label="{{ __('Endpoint') }}"><button class="btn btn-light" type="button" data-copy="#wfEndpoint">{{ __('Copy') }}</button></div>
      <label class="form-label small" for="wfEmbed">{{ __('Plain HTML form (no JavaScript required)') }}</label>
      <textarea class="form-control form-control-sm font-monospace" id="wfEmbed" rows="12" readonly></textarea>
      <div class="d-flex gap-2 mt-2"><button class="btn btn-light btn-sm" type="button" data-copy="#wfEmbed">{{ __('Copy HTML') }}</button></div>
      <details class="mt-3"><summary class="small">{{ __('Accepted fields & JSON example') }}</summary>
        <p class="small text-muted mt-2 mb-1">{{ __('name, phone, whatsapp, email, city, company, message, interest, budget, location, preferred_contact, consent / consent_whatsapp / consent_phone / consent_email, utm_source, utm_medium, utm_campaign, utm_content, utm_term, fbclid, gclid, page_url, referrer. Common aliases (full_name, mobile, tel, notes, product…) are mapped automatically. A filled "website" field is treated as spam.') }}</p>
        <pre class="small bg-light p-2 rounded mb-0" id="wfJsonExample"></pre>
      </details>
      <h3 class="h6 mt-3">{{ __('Recent submissions') }}</h3>
      <div id="wfSubmissions" class="small"></div>
    </div>
  </div></div>
</div>
@endsection
@push('scripts')<script src="/js/pages/integrations.js?v={{ config('app.asset_version') }}"></script>@endpush
