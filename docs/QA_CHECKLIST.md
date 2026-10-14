# Manual QA checklist (Phase 2)

Use `php artisan db:seed --class=DemoSeeder` on a non-production copy. Test on desktop (≥1280px), tablet (768px) and phone (390px).

**Access & security**
- [ ] Wrong password shows a generic error; 6th attempt within a minute returns "Too many requests".
- [ ] Sales rep sees only own leads (list, pipeline, dashboard, follow-ups, global search); opening another rep's lead URL gives 404.
- [ ] Manager sees team members' leads; cannot assign to someone outside the team.
- [ ] Analyst sees dashboard and campaign aggregates only; `/leads` gives 403.
- [ ] Deactivating a user signs them out on their next request.
- [ ] Browser console shows no CSP violations on any page.

**Leads & contacts**
- [ ] Create lead with `0300 1234567` → stored `+923001234567`, "entered as" shows original. UK/UAE/KSA/US numbers with the right country.
- [ ] Typing an existing number shows "Existing contact found"; saving with an open lead is blocked until "separate, new inquiry" is ticked.
- [ ] Invalid phone (e.g. `12345`) is rejected with a field error.
- [ ] Filters, sorting, column chooser, saved view, URL sharing of filters all work; Reset clears.
- [ ] Bulk assign / status / priority / tags / follow-up / archive; closing statuses and archive require confirmation; skipped leads are listed.
- [ ] Lead detail: edit, stage change (Lost requires reason; Do Not Contact requires reason and cancels follow-ups), priority, assignee, note, consent record, opt-out.
- [ ] Contact page: weak (same-name) duplicate is suggested but never merged automatically; merge moves leads and fills only blank fields; audit log entry exists.

**Calls & follow-ups**
- [ ] Log "No answer" three times → stage "Contact Attempted", 3 unanswered, never "Not interested".
- [ ] "Callback requested" demands a time and creates a high-priority follow-up.
- [ ] Overdue / Today / Upcoming / Missed tabs and counts; reschedule and cancel (reason required).
- [ ] Dashboard "Overdue follow-ups" matches the Overdue tab.
- [ ] Appointment creation moves an early-stage lead to "Appointment"; calendar shows it; status update from calendar.

**Pipeline**
- [ ] Drag between open stages; drag to Lost asks for reason; cancel reverts the card.
- [ ] "Show closed" expands closed columns.

**Import / export**
- [ ] Upload a real exported sheet (XLSX with numeric phone cells, CSV with BOM, semicolon-delimited CSV).
- [ ] Auto-mapping; dry run shows errors (bad phone, unknown status/owner, future date) and reviews (duplicates in file / existing contacts).
- [ ] Commit imports only actionable rows; report CSV lists every other row with reason.
- [ ] Re-importing the same file with an external ID column creates no duplicates.
- [ ] Export CSV/XLSX opens in Excel; a name like `=1+1` shows as text; phone numbers keep `+`. Export appears in audit log.

**Campaigns & dashboard**
- [ ] With no spend, ad cards say "Data unavailable" (never 0).
- [ ] After entering daily spend, CPL = spend ÷ attributed leads; renaming a campaign keeps old name on existing leads' attribution.
- [ ] Every date preset and a custom range update all cards and charts.

**Settings**
- [ ] Rename/reorder/add stages; default stage must be open; stage with leads cannot be disabled.
- [ ] Outcome "nobody answered" cannot be set to move leads to Not interested.
- [ ] Role permission change takes effect immediately and is audited.

**WhatsApp & AI** (needs a test WABA number, or use the demo seed for UI-only checks)
- [ ] Integrations: save WhatsApp credentials → webhook URL + verify token appear; Meta's "Verify and save" succeeds; Test connection shows the number.
- [ ] Send a WhatsApp message from a phone → conversation, contact and lead appear within seconds; owner gets a notification once.
- [ ] Message from a click-to-WhatsApp ad → lead source Facebook/Instagram Ads, attribution shows ad id/headline.
- [ ] Reply from the inbox → tick goes clock → ✓ → ✓✓ → blue ✓✓ as Meta reports; failure shows the reason.
- [ ] After 24 h without a customer message, free text is blocked and templates are offered.
- [ ] Customer sends "STOP" → opted out, no further sends possible.
- [ ] Suggest mode: "AI draft" produces a grounded answer with sources; editing then sending puts it in the learning queue.
- [ ] Auto mode: a question covered by the knowledge base gets an AI reply; an uncovered one gets the handoff message and appears under "Needs human".
- [ ] Off-topic request ("write my essay") is declined.
- [ ] Urdu, Roman Urdu and Arabic questions are answered in the same language.
- [ ] Approve an answer in the learning queue → Knowledge Base shows a *Learned* entry → same question is now answered.
- [ ] Knowledge Base: upload PDF/DOCX; "Test retrieval" finds the right passage; disabling a document removes it from answers.
- [ ] Lead detail shows score, factors and AI summary; AI never overwrote a field already filled.
- [ ] Analytics: numbers match the inbox for the same range; CSV exports open in Excel.
- [ ] Profile → language اردو / العربية switches the interface to RTL.
- [ ] Platform Admin: create a business, sign in as its admin, confirm none of the first business's data is visible.

## Automation, notifications & Meta (manual checks with real credentials)
- [ ] Automation Rules lists the five starter rules; "Assign new leads…" is off. Enable it, create a lead → it is assigned round-robin and a first-contact task appears; the rep is notified once.
- [ ] Create a lead assigned to a rep and leave it uncontacted past the SLA → within 5 minutes the rep and the manager each get one "SLA missed" notification, and not again on the next scan.
- [ ] Log three "No answer" calls in a row → owner gets the "try WhatsApp" prompt once; a fourth call does not repeat it.
- [ ] Build a rule with two `move_stage` rules pointing at each other → execution history shows a `skipped` loop-guard entry, no infinite loop.
- [ ] ▶ Test a rule against a lead → per-condition results; no execution is recorded.
- [ ] Schedule a callback 10 minutes out → "Callback in … min" notification before it is due.
- [ ] With `MAIL_MAILER=smtp`: an email arrives for a type that is on in Profile → Email notifications; switching it off stops emails; with the log mailer the profile page says mail is not configured.
- [ ] Integrations → Meta: wrong token → card shows *Error* with the Graph message; correct token → *Connected* with account name, currency and time zone; the token never appears in the page source or network responses.
- [ ] Sync now → Campaigns page shows the Meta campaigns with spend for the synced range; an existing manual campaign of the same name is linked (count unchanged); for a day with both manual and API spend, the API figure is used.
- [ ] Ad account in another currency → yellow warning on the card and the dashboard shows CPL as unavailable.
- [ ] Lead Ads Testing Tool → a test lead appears within seconds with source *Meta Instant Forms*, full campaign/ad set/ad attribution, form name in notes; re-sending the same test lead creates nothing new.
- [ ] Stop the queue worker, submit a form, restart → the hourly backfill (or "Reload 30 days") creates the lead exactly once.
- [ ] Submit a form with no phone/email → no contact is created; the card counts it under "without contact details".

## Audiences & retargeting
- [ ] Audience Segments lists the 11 suggested audiences and the Do Not Contact exclusion list; Recount fills members/eligible.
- [ ] Build a segment with two groups joined by OR; Estimate shows members vs eligible with the reasons for anyone left out; save → it appears with counts.
- [ ] Mark a lead Do Not Contact → after Recount it is counted under "opted out" and never appears in preview, CSV or Meta upload.
- [ ] Record a withdrawn ads-audience consent on a contact → left out under "consent"; switch the segment to "Explicit consent" → only contacts with granted consent remain.
- [ ] Attach an exclusion list (e.g. Converted customers as exclusion) → those people are left out under "on an exclusion list".
- [ ] Export CSV (needs export permission): opens in Excel, no formulas execute; hashed export has no readable phone/email; both appear in Audit Logs and in sync history.
- [ ] With Meta connected (ads_management, CA terms accepted): "Create Meta audience & sync" → job goes queued → success on Retargeting; Ads Manager shows the audience; after Meta matching, size range and delivery status appear on Retargeting.
- [ ] Opt someone out, sync again → Meta audience is replaced without them (check `uploaded` dropped by one).
- [ ] Token without ads_management or terms not accepted → job fails with Meta's reason; nothing marked synced; admin notified.
- [ ] Lead timeline shows "Added to audience" / "Left audience" events after recounts.

## Production readiness
- [ ] `php artisan leadflow:doctor` on the production server exits 0; Platform Admin shows the same checks with worker and scheduler heartbeats green.
- [ ] Create an API token on My profile, call `GET /api/v1/leads` with curl and a bearer header → 200; revoke → 401; a wrong token → 401 and never a stack trace.
- [ ] Erase a test contact → name/phone/email gone from the contact page, lead list search, WhatsApp inbox and exports; audit log entry present; a new inbound message from the same number is suppressed.
- [ ] Set "Archive closed leads after" to 30 days on a business with old won/lost leads → `php artisan leadflow:retention --dry-run` lists them; the real run archives them and logs `retention.applied`.
- [ ] `php artisan leadflow:backup` produces a zip; restore it into a scratch database following README → Backup and restore; the app boots against it.
- [ ] Trigger an error (e.g. temporarily wrong DB password on a sub-feature) → platform admins get one "Application error" notification, not one per request.
- [ ] Review Settings → Organization → Data retention values with the business owner.

## Two-factor & webhooks
- [ ] Profile → Set up two-factor: scan with Google Authenticator; wrong code rejected; right code enables and shows 10 recovery codes.
- [ ] Sign out, sign in → code page; wrong code shows an error; the same code twice is rejected; a recovery code works once.
- [ ] Settings → Security → Require 2FA: an unenrolled user is sent to their profile and the API returns `two_factor_required`; after enrolling everything works.
- [ ] Team → shield icon → reset a user's 2FA; they can sign in with password only and are prompted to enrol again.
- [ ] Automation rule with "Call a webhook" to a Zapier catch hook → the zap receives the lead JSON with `X-LeadFlow-Signature`; a private/http URL is refused when saving.
