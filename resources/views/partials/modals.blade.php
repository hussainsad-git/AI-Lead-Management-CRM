{{-- Shared modals. Options are filled from /api/v1/lookups by core.js. --}}

<div class="modal fade" id="leadModal" tabindex="-1" aria-labelledby="leadModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" id="leadForm" novalidate>
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="leadModalTitle">New lead</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="small-caps mb-2">Contact</div>
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label" for="lf-name">Name</label><input class="form-control" id="lf-name" name="contact[name]" maxlength="160"></div>
                    <div class="col-md-6"><label class="form-label" for="lf-company">Company</label><input class="form-control" id="lf-company" name="contact[company_name]" maxlength="160"></div>
                    <div class="col-md-2 col-4"><label class="form-label" for="lf-region">Country</label><select class="form-select" id="lf-region" name="contact[phone_region]" data-fill="regions"></select></div>
                    <div class="col-md-5 col-8"><label class="form-label" for="lf-phone">Phone</label><input class="form-control" id="lf-phone" name="contact[phone]" inputmode="tel" maxlength="40" placeholder="0300 1234567 or +44..."></div>
                    <div class="col-md-5"><label class="form-label" for="lf-wa">WhatsApp number</label><input class="form-control" id="lf-wa" name="contact[whatsapp]" inputmode="tel" maxlength="40" placeholder="Leave blank if same as phone"></div>
                    <div class="col-md-6"><label class="form-label" for="lf-email">Email</label><input class="form-control" id="lf-email" name="contact[email]" type="email" maxlength="191"></div>
                    <div class="col-md-3 col-6"><label class="form-label" for="lf-city">City</label><input class="form-control" id="lf-city" name="contact[city]" maxlength="120"></div>
                    <div class="col-md-3 col-6"><label class="form-label" for="lf-type">Type</label><select class="form-select" id="lf-type" name="contact[type]"><option value="individual">Individual</option><option value="business">Business</option></select></div>
                    <div class="col-12"><div class="match-box" id="leadMatchBox" role="status"></div></div>
                </div>
                <hr>
                <div class="small-caps mb-2">Inquiry</div>
                <div class="row g-2">
                    <div class="col-md-4"><label class="form-label" for="lf-source">Source <span class="text-danger">*</span></label><select class="form-select" id="lf-source" name="lead_source_id" data-fill="sources" required></select></div>
                    <div class="col-md-4"><label class="form-label" for="lf-assign">Assign to</label><select class="form-select" id="lf-assign" name="assigned_user_id" data-fill="users" data-empty="Unassigned"></select></div>
                    <div class="col-md-4"><label class="form-label" for="lf-priority">Priority</label><select class="form-select" id="lf-priority" name="priority"><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option><option value="low">Low</option></select></div>
                    <div class="col-md-6"><label class="form-label" for="lf-interest">Interested in</label><input class="form-control" id="lf-interest" name="product_interest" maxlength="191"></div>
                    <div class="col-md-3 col-6"><label class="form-label" for="lf-budget">Budget</label><input class="form-control" id="lf-budget" name="budget_amount" type="number" min="0" step="0.01"></div>
                    <div class="col-md-3 col-6"><label class="form-label" for="lf-location">Location</label><input class="form-control" id="lf-location" name="location" maxlength="160"></div>
                    <div class="col-md-4"><label class="form-label" for="lf-campaign">Campaign</label><select class="form-select" id="lf-campaign" name="attribution[campaign_id]" data-fill="campaigns" data-empty="— Not known —"></select></div>
                    <div class="col-md-4"><label class="form-label" for="lf-adset">Ad set (if known)</label><input class="form-control" id="lf-adset" name="attribution[ad_set_name]" maxlength="191"></div>
                    <div class="col-md-4"><label class="form-label" for="lf-ad">Ad (if known)</label><input class="form-control" id="lf-ad" name="attribution[ad_name]" maxlength="191"></div>
                    <div class="col-12"><label class="form-label" for="lf-srcnotes">Source notes</label><textarea class="form-control" id="lf-srcnotes" name="source_notes" rows="2" maxlength="5000" placeholder="e.g. Clicked 'Send message' on the Instagram reel ad; asked about 2-bed price"></textarea></div>
                    <div class="col-12"><label class="form-label" for="lf-tags">Tags</label><input class="form-control" id="lf-tags" name="tags_csv" placeholder="Comma separated"></div>
                    <div class="col-12">
                        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="lf-consent-wa" name="consents[whatsapp]" value="1"><label class="form-check-label" for="lf-consent-wa">Contact agreed to WhatsApp follow-up</label></div>
                        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="lf-consent-ph" name="consents[phone]" value="1"><label class="form-check-label" for="lf-consent-ph">Agreed to phone calls</label></div>
                    </div>
                </div>
                <input type="hidden" name="contact_id" value="">
                <input type="hidden" name="allow_duplicate_open_lead" value="">
            </div>
            <div class="modal-footer">
                <span class="me-auto small text-muted">Only enter details the person actually provided.</span>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Create lead</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="callModal" tabindex="-1" aria-labelledby="callModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="callForm" novalidate>
            <div class="modal-header"><h2 class="modal-title fs-5" id="callModalTitle">Log call outcome</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="mb-2 small text-muted" id="callModalContext"></div>
                <div class="mb-3"><label class="form-label" for="cf-outcome">Outcome <span class="text-danger">*</span></label><select class="form-select" id="cf-outcome" name="call_outcome_id" data-fill="outcomes" data-empty="Choose outcome" required></select>
                    <div class="form-text">Unanswered calls never mark a lead as not interested.</div></div>
                <div class="mb-3 hidden" id="cf-callback-wrap"><label class="form-label" for="cf-callback">Callback at <span class="text-danger">*</span></label><input class="form-control" type="datetime-local" id="cf-callback" name="callback_at"></div>
                <div class="mb-3"><label class="form-label" for="cf-notes">Notes</label><textarea class="form-control" id="cf-notes" name="notes" rows="3" maxlength="5000"></textarea></div>
                <div class="row g-2">
                    <div class="col-5"><label class="form-label" for="cf-duration">Duration (min)</label><input class="form-control" type="number" min="0" step="0.5" id="cf-duration" name="duration_minutes" placeholder="Optional"></div>
                    <div class="col-7"><label class="form-label" for="cf-next">Next follow-up</label><input class="form-control" type="datetime-local" id="cf-next" name="next_follow_up_at"></div>
                    <div class="col-12"><label class="form-label" for="cf-nextaction">Next action</label><input class="form-control" id="cf-nextaction" name="next_action" maxlength="191" placeholder="e.g. Send price list, call after Friday prayers"></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="followUpModal" tabindex="-1" aria-labelledby="followUpModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="followUpForm" novalidate>
            <div class="modal-header"><h2 class="modal-title fs-5" id="followUpModalTitle">Schedule follow-up</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label" for="fu-at">When <span class="text-danger">*</span></label><input class="form-control" type="datetime-local" id="fu-at" name="scheduled_at" required></div>
                <div class="mb-3"><label class="form-label" for="fu-purpose">Purpose</label><input class="form-control" id="fu-purpose" name="purpose" maxlength="191" value="Follow-up call"></div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label" for="fu-priority">Priority</label><select class="form-select" id="fu-priority" name="priority"><option value="">Same as lead</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option><option value="low">Low</option></select></div>
                    <div class="col-6"><label class="form-label" for="fu-user">Assignee</label><select class="form-select" id="fu-user" name="assigned_user_id" data-fill="users" data-empty="Lead owner"></select></div>
                </div>
                <div class="mt-3"><label class="form-label" for="fu-notes">Notes</label><textarea class="form-control" id="fu-notes" name="notes" rows="2" maxlength="2000"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Schedule</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="apptModal" tabindex="-1" aria-labelledby="apptModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="apptForm" novalidate>
            <div class="modal-header"><h2 class="modal-title fs-5" id="apptModalTitle">Schedule appointment</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-8"><label class="form-label" for="ap-title">Title <span class="text-danger">*</span></label><input class="form-control" id="ap-title" name="title" maxlength="191" required value="Site visit"></div>
                    <div class="col-4"><label class="form-label" for="ap-type">Type</label><select class="form-select" id="ap-type" name="type"><option value="site_visit">Site visit</option><option value="meeting">Meeting</option><option value="video">Video call</option><option value="call">Call</option></select></div>
                    <div class="col-6"><label class="form-label" for="ap-start">Starts <span class="text-danger">*</span></label><input class="form-control" type="datetime-local" id="ap-start" name="starts_at" required></div>
                    <div class="col-6"><label class="form-label" for="ap-end">Ends</label><input class="form-control" type="datetime-local" id="ap-end" name="ends_at"></div>
                    <div class="col-12"><label class="form-label" for="ap-loc">Location</label><input class="form-control" id="ap-loc" name="location" maxlength="191"></div>
                    <div class="col-12"><label class="form-label" for="ap-user">Assignee</label><select class="form-select" id="ap-user" name="assigned_user_id" data-fill="users" data-empty="Lead owner"></select></div>
                    <div class="col-12"><label class="form-label" for="ap-notes">Notes</label><textarea class="form-control" id="ap-notes" name="notes" rows="2" maxlength="2000"></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Schedule</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="confirmForm" novalidate>
            <div class="modal-header"><h2 class="modal-title fs-5" id="confirmModalTitle">Confirm</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div id="confirmBody"></div>
                <div class="mt-3 hidden" id="confirmReasonWrap"><label class="form-label" for="confirmReason" id="confirmReasonLabel">Reason</label><textarea class="form-control" id="confirmReason" rows="2" maxlength="255"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit" id="confirmOk">Confirm</button></div>
        </form>
    </div>
</div>
