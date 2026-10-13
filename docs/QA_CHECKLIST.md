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
