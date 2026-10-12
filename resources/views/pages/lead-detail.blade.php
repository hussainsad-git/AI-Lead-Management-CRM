@extends('layouts.app')
@section('title', 'Lead')
@section('content')
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2"><li class="breadcrumb-item"><a href="/leads">Leads</a></li><li class="breadcrumb-item active" aria-current="page" id="crumb">Lead</li></ol></nav>
<div id="leadRoot">{!! '<div class="lf-loading"><span class="spinner-border spinner-border-sm me-2"></span>Loading lead…</div>' !!}</div>

<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg"><form class="modal-content" id="editForm" novalidate>
        <div class="modal-header"><h2 class="modal-title fs-5" id="editModalTitle">Edit lead</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <div class="small-caps mb-2 contact-edit">Contact</div>
            <div class="row g-2 contact-edit">
                <div class="col-md-6"><label class="form-label" for="e-name">Name</label><input class="form-control" id="e-name" name="contact[name]" maxlength="160"></div>
                <div class="col-md-6"><label class="form-label" for="e-company">Company</label><input class="form-control" id="e-company" name="contact[company_name]" maxlength="160"></div>
                <div class="col-md-2 col-4"><label class="form-label" for="e-region">Country</label><select class="form-select" id="e-region" name="contact[phone_region]" data-fill="regions"></select></div>
                <div class="col-md-5 col-8"><label class="form-label" for="e-phone">Phone</label><input class="form-control" id="e-phone" name="contact[phone]" maxlength="40"></div>
                <div class="col-md-5"><label class="form-label" for="e-wa">WhatsApp</label><input class="form-control" id="e-wa" name="contact[whatsapp]" maxlength="40"></div>
                <div class="col-md-6"><label class="form-label" for="e-email">Email</label><input class="form-control" id="e-email" name="contact[email]" maxlength="191"></div>
                <div class="col-md-3 col-6"><label class="form-label" for="e-city">City</label><input class="form-control" id="e-city" name="contact[city]" maxlength="120"></div>
                <div class="col-md-3 col-6"><label class="form-label" for="e-pref">Preferred contact</label><select class="form-select" id="e-pref" name="contact[preferred_contact_method]"><option value="">—</option><option value="phone">Phone</option><option value="whatsapp">WhatsApp</option><option value="email">Email</option></select></div>
            </div>
            <hr class="contact-edit">
            <div class="small-caps mb-2">Inquiry</div>
            <div class="row g-2">
                <div class="col-md-4"><label class="form-label" for="e-source">Source</label><select class="form-select" id="e-source" name="lead_source_id" data-fill="sources"></select></div>
                <div class="col-md-4"><label class="form-label" for="e-interest">Interested in</label><input class="form-control" id="e-interest" name="product_interest" maxlength="191"></div>
                <div class="col-md-4"><label class="form-label" for="e-location">Location</label><input class="form-control" id="e-location" name="location" maxlength="160"></div>
                <div class="col-md-3 col-6"><label class="form-label" for="e-budget">Budget</label><input class="form-control" type="number" min="0" step="0.01" id="e-budget" name="budget_amount"></div>
                <div class="col-md-3 col-6"><label class="form-label" for="e-deal">Deal value</label><input class="form-control" type="number" min="0" step="0.01" id="e-deal" name="deal_value"></div>
                <div class="col-md-6"><label class="form-label" for="e-tags">Tags</label><input class="form-control" id="e-tags" name="tags_csv" placeholder="Comma separated"></div>
                <div class="col-12"><label class="form-label" for="e-srcnotes">Source notes</label><textarea class="form-control" id="e-srcnotes" name="source_notes" rows="2" maxlength="5000"></textarea></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save changes</button></div>
    </form></div>
</div>

<div class="modal fade" id="consentModal" tabindex="-1" aria-labelledby="consentModalTitle" aria-hidden="true">
    <div class="modal-dialog"><form class="modal-content" id="consentForm" novalidate>
        <div class="modal-header"><h2 class="modal-title fs-5" id="consentModalTitle">Record consent</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <div class="row g-2">
                <div class="col-6"><label class="form-label" for="c-channel">Channel</label><select class="form-select" id="c-channel" name="channel"><option value="whatsapp">WhatsApp</option><option value="phone">Phone</option><option value="sms">SMS</option><option value="email">Email</option><option value="ads_audience">Ad audiences</option></select></div>
                <div class="col-6"><label class="form-label" for="c-status">Status</label><select class="form-select" id="c-status" name="status"><option value="granted">Granted</option><option value="withdrawn">Withdrawn (opt-out)</option></select></div>
                <div class="col-12"><label class="form-label" for="c-source">How was it given?</label><select class="form-select" id="c-source" name="source"><option value="verbal">Verbally on a call</option><option value="whatsapp_message">In a WhatsApp message</option><option value="web_form">Web form</option><option value="instant_form">Meta Instant Form</option><option value="written">In writing</option><option value="other">Other</option></select></div>
                <div class="col-12"><label class="form-label" for="c-evidence">Evidence / note</label><input class="form-control" id="c-evidence" name="evidence" maxlength="500" placeholder="e.g. Said 'yes, send details on WhatsApp' on 12 Oct call"></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Record</button></div>
    </form></div>
</div>
@endsection
@push('scripts')<script src="/js/pages/lead-detail.js?v={{ config('app.asset_version') }}"></script>@endpush
