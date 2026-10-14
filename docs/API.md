# LeadFlow REST API (v1)

Base path `/api/v1`. Session-authenticated (same cookie as the web UI) with CSRF: send `X-CSRF-TOKEN` (from `<meta name="csrf-token">`) on every non-GET request and `Accept: application/json`. Rate limit: 240 req/min per user; sensitive endpoints (export, bulk, import upload, merge, user/role changes) 10/min. Token auth for external systems is planned for Phase 6.

## Authentication
Two ways to call `/api/v1`: the browser session (cookie + `X-CSRF-TOKEN`, used by the app) or a **personal API token** created on *My profile → API tokens* and sent as `Authorization: Bearer lf_…`. Token requests need no CSRF token and no cookie; they run with the owner's permissions inside the owner's organization and are rate-limited per user. Example:
```bash
curl -s https://crm.example.com/api/v1/leads -H "Authorization: Bearer lf_…" -H "Accept: application/json"
curl -s -X POST https://crm.example.com/api/v1/leads -H "Authorization: Bearer lf_…" -H "Content-Type: application/json" \
  -d '{"contact":{"name":"Ali","phone":"03001234567"},"lead_source_id":5,"product_interest":"5 marla plot","attribution":{"platform":"web","source":"landing_page","campaign_name":"Oct plots"}}'
```

### Two-factor & tokens
`GET profile/two-factor`, `POST profile/two-factor/begin` (returns secret, otpauth URI and QR SVG), `POST profile/two-factor/confirm {code}` (returns 10 recovery codes once), `POST profile/two-factor/disable {current_password}`, `POST profile/two-factor/recovery-codes {current_password}`; admins: `POST users/{id}/reset-two-factor`. Browser session only. When the organization requires 2FA, unenrolled session users get `403 two_factor_required` on every other endpoint.

## Conventions

Success: `{"data": ..., "meta": {...}}`. Paginated lists: `meta = {page, per_page, total, last_page}`; `?page=&per_page=` (5–100).
Errors: `{"error": {"code": "...", "message": "human readable", "details": {...}}}` with HTTP status:

| status | code | when |
|---|---|---|
| 401 | unauthenticated | no/expired session |
| 403 | forbidden | missing permission |
| 404 | not_found | missing **or not visible to you** (other org / other rep's lead) |
| 409 | duplicate_open_lead, task_not_open, import_wrong_state | state conflicts |
| 419 | csrf_mismatch | stale CSRF token |
| 422 | validation_failed (`details` = field → messages), reason_required, contact_suppressed, confirmation_required, callback_time_required, invalid_date_range, … | |
| 429 | rate_limited | |

Timestamps are ISO-8601 UTC (`2026-10-12T06:00:00Z`). Datetime inputs may be ISO with offset, or wall-clock `YYYY-MM-DDTHH:mm`, which is interpreted in the user's/organization's time zone. Money is a decimal string with an explicit currency.

## Endpoints

| Method & path | Permission | Notes |
|---|---|---|
| GET `lookups` | any | statuses, sources, outcomes, users, teams, tags, campaigns, my permissions |
| GET `dashboard?range=today\|yesterday\|last_7_days\|last_30_days\|this_month\|previous_month\|all_time\|custom&from&to` | dashboard.view | scoped to visibility; see metric definitions below |
| GET `leads` | leads.view_* | filters: `q, status_ids[], status_category[], source_ids[], assigned_user_ids[] (or "unassigned"), campaign_ids[], tag_ids[], priority[], created_from, created_to, follow_up=overdue\|today\|upcoming\|none, contacted=yes\|no, responded=yes\|no, attempts_min, attempts_max, opt_out=opted_out\|contactable, archived=exclude\|include\|only`; `sort=id\|created_at\|last_activity_at\|next_follow_up_at\|last_contacted_at\|priority\|name\|status`, `dir` |
| POST `leads` | leads.create | `contact{name,company_name,phone,whatsapp,email,phone_region,city,type}` or `contact_id`; `lead_source_id` required; optional status, assignee, priority, interest, budget, attribution{campaign_id,platform,ad_set_name,ad_name}, tags[], consents{whatsapp,phone,email}, `allow_duplicate_open_lead` |
| GET `leads/{id}` | visibility | lead + attribution + consent summary + tasks + appointments + stage history |
| PATCH `leads/{id}` | leads.update (+contacts.update for `contact`) | partial update |
| GET `leads/{id}/timeline` | visibility | activities incl. contact-level events |
| POST `leads/{id}/activities` | leads.update | internal note `{body}` |
| POST `leads/{id}/status` | leads.update | `{lead_status_id, reason}`; reason required for lost / DNC stages |
| POST `leads/{id}/assign` | leads.assign | `{assigned_user_id|null, reason}`; team managers limited to their team |
| POST `leads/{id}/archive` | leads.archive | `{reason}` (audited) |
| POST `leads/{id}/follow-ups` | followups.manage_* | `{scheduled_at, purpose, priority, assigned_user_id, notes}` |
| POST `leads/{id}/calls` | followups.manage_* | ad-hoc call: `{call_outcome_id, notes, duration_minutes, next_follow_up_at, callback_at, next_action}` |
| POST `leads/{id}/appointments` | followups.manage_* | `{title, type, starts_at, ends_at, location, notes, assigned_user_id}` |
| POST `leads/bulk` | leads.bulk_update (+ action permission) | `{action: assign\|status\|priority\|tags_add\|follow_up\|archive, ids[] or filters{}, confirm}`; ≤5000 leads; per-lead failures returned |
| POST `leads/export` | leads.export | `{format: csv\|xlsx, columns[], ids[] or filters{}}` → file download (audited) |
| GET `pipeline` | leads.view_* | columns with counts and up to 40 cards; same filters as `leads`; `include_closed=1` |
| GET `contacts`, `contacts/{id}` | contacts.view | visible = has at least one visible lead |
| PATCH `contacts/{id}` | contacts.update | |
| GET `contacts/match?phone&whatsapp&email&phone_region` | leads.view_* | live duplicate check (no PII for contacts you can't see) |
| GET `contacts/{id}/duplicates` | contacts.merge | strong (identifier) + weak (name) candidates |
| POST `contacts/{id}/merge` | contacts.merge | `{source_contact_id, confirm: true}` (audited) |
| POST `contacts/{id}/consents` | consent.manage | `{channel, status: granted\|withdrawn, source, evidence}` |
| POST `contacts/{id}/opt-out` | consent.manage | `{channel: all\|whatsapp\|phone\|sms\|email\|ads_audience, reason}` |
| POST `suppressions/{id}/lift` | consent.manage + (contacts.merge or settings.manage) | `{reason}` (audited) |
| GET `call-tasks?view=overdue\|today\|upcoming\|missed\|completed\|cancelled` | followups.manage_* | `meta.counts` per queue |
| POST `call-tasks/{id}/complete` \| `reschedule` \| `cancel` | followups.manage_* | |
| GET `appointments?from&to` (≤62 days), PATCH `appointments/{id}` `{status}` | followups.manage_* | |
| GET/POST `campaigns`, PATCH `campaigns/{id}` | campaigns.view / campaigns.manage | |
| GET/POST `campaigns/{id}/spend`, DELETE `campaigns/{id}/spend/{spendId}` | campaigns.view / campaigns.manage | daily manual spend in campaign currency (upsert per date) |
| POST `imports` (multipart `file`) → `imports/{id}/configure` `{mapping{col: field}, options}` → GET `imports/{id}` (poll) → GET `imports/{id}/rows?action=` → POST `imports/{id}/resolve` → POST `imports/{id}/commit` → GET `imports/{id}/report` | imports.run | see import flow below |
| GET/POST/DELETE `views` | any | personal saved filters |
| GET/POST/PATCH `users`, `teams`; GET/PATCH `roles` | users.manage / roles.manage | |
| GET `settings`; PATCH `settings/organization`; POST/PATCH `settings/statuses`, `settings/sources`, `settings/outcomes`; POST `settings/statuses/reorder` | settings.manage | |
| GET `audit-logs?action=prefix&user_id` | audit.view | |
| GET `integrations/status` | integrations.manage | honest status; all `not_configured` until Phase 4 |

### Import flow

1. Upload (CSV or XLSX, ≤ `IMPORT_MAX_ROWS`). Headers are detected and columns auto-mapped by synonym.
2. Configure mapping + options: `duplicate_strategy` (`review` default, `new_lead`, `update_contact`, `skip`), `default_region`, `default_source_id`, `default_assignee_id`, `date_order` (`dmy`/`mdy`).
3. A queued dry run gives every row an action: `create`, `new_lead`, `update`, `skip`, `review`, `error` — with messages. Duplicates within the file and against existing contacts are flagged; external lead ids already present are skipped (idempotent re-import).
4. Resolve review rows, then commit. Each row is its own transaction; failures are recorded per row. The report CSV lists every row not imported with its reason and original values.

### Metric definitions (dashboard)

* **Cohort**: rates and funnel use leads *created* in the selected range (organization time zone).
* **Contacted** = has a logged call attempt. **Responded** = connected call or a stage at/after *Responded*. **Unresponsive** = contacted but no response yet.
* **Conversion rate** = converted ÷ leads in range × 100. A won deal that is reopened stops counting.
* **Cost per lead** = manually recorded spend in range (organization currency only) ÷ leads in range first-touch attributed to campaigns that have spend in range. Not shown without spend; foreign-currency rows are excluded and reported.
* **CPQL / CAC** use the same spend over qualified / converted attributed leads. CAC is ad spend only.
* **Awaiting first contact** and **Overdue follow-ups** are current-state queues.


## AI, WhatsApp & admin endpoints

| Method & path | Permission | Notes |
|---|---|---|
| GET `conversations?view=all\|mine\|unassigned\|handoff\|unread\|closed&q` | whatsapp.view | visibility follows lead visibility; `meta.counts` |
| GET `conversations/{id}` | whatsapp.view | messages, lead + score factors, 24h window, latest AI suggestion, approved templates |
| GET `conversations/{id}/poll?after_id` | whatsapp.view | new messages + status changes |
| PATCH `conversations/{id}` | whatsapp.view (+leads.assign to assign others) | `{ai_mode: inherit\|on\|off, status, assigned_user_id, resolve_handoff}` |
| POST `conversations/{id}/messages` | whatsapp.send | `{body, interaction_id?}` or `{template_id, params[]}`; 422 `window_closed` / `contact_suppressed`; 502 `whatsapp_send_failed` |
| POST `conversations/{id}/suggest` | whatsapp.send | AI draft with sources (not sent) |
| POST `conversations/{id}/read` | whatsapp.view | clears unread, sends read receipt |
| POST `ai-interactions/{id}/feedback` | whatsapp.send / ai.manage (+kb.manage to teach) | `{feedback: up\|down\|corrected, corrected_text, question, teach}` |
| GET/PUT `ai/assistant`, POST `ai/assistant/preset` | ai.manage | provider, model, temperature, mode, hours, languages, prompts, handoff keywords, fields |
| GET `ai/models?provider=` | ai.manage | live model list from the provider |
| POST `ai/playground` | ai.manage | `{question, history[]}` → reply, flags, sources, latency, tokens |
| GET `ai/learning`, POST `ai/learning/{id}/dismiss` | ai.manage | learning queue |
| POST/PATCH/DELETE `ai/rules[/{id}]` | ai.manage | auto-reply rules |
| GET/POST `kb/documents`, GET/PATCH/DELETE `kb/documents/{id}`, POST `kb/documents/{id}/reindex`, GET `kb/search?q=` | kb.manage (read: ai.manage) | multipart `file` or `{type: text\|faq, ...}` |
| GET `integrations/status`, PUT `integrations/{whatsapp_cloud\|openai\|deepseek\|meta_marketing}`, POST `.../test`, POST `integrations/{whatsapp_cloud\|meta_marketing}/rotate-webhook \| verify-token`, POST `integrations/whatsapp_cloud/templates/sync` | integrations.manage | secrets write-only |
| POST `integrations/meta_marketing/sync {days?}`, POST `integrations/meta_marketing/subscribe-page` | integrations.manage | inline sync (campaigns, spend, events, Instant Form backfill); 422 `sync_failed` with the Graph error |
| GET `automation/meta` | automation.manage | triggers, fields with options, operators, actions, pickers, placeholders |
| GET/POST `automation/rules`, PATCH/DELETE `automation/rules/{id}` | automation.manage | `{name, description, trigger, delay_minutes, conditions:{match, rules:[{field, op, value}]}, actions:[{type, …}], is_active}`; PATCH with only `{is_active}` toggles |
| POST `automation/rules/{id}/test {lead_id}` | automation.manage | dry run: per-condition pass/fail, `would_run`; never performs actions |
| GET `automation/executions?rule_id&status&page` | automation.manage | execution history with per-action results |
| PUT `profile/notifications {email:{type:bool}}` | any | per-type email preferences; `GET profile` returns `notifications.email_enabled` (false when MAIL_MAILER is log/array) |
| GET `analytics`, GET `analytics/export?section=sources\|campaigns\|salespeople` | reports.view | same date/filters as dashboard + `source_id, user_id, campaign_id, platform` |
| GET `notifications`, POST `notifications/read {ids?}` | any | own notifications |
| GET/PATCH `profile`, POST `profile/password` | any | password change signs out other sessions |
| PATCH `settings/business`, POST `settings/logo` | settings.manage | industry, colour, locale, hours, auto-assign, scoring weights |
| GET `platform`, POST `platform/organizations`, PATCH `platform/organizations/{id}` | platform admin | counts only, no customer records |
| GET `audiences/meta`, GET/POST `audiences`, GET/PATCH/DELETE `audiences/{id}`, POST `audiences/estimate`, POST `audiences/{id}/refresh` | audiences.manage | segment builder; counts = members vs eligible with per-reason skips; previews masked |
| GET `audiences/{id}/export?hashed=0\|1` | audiences.manage + leads.export | audited; hashed variant = SHA-256 phone/email/country only |
| POST `audiences/{id}/sync` (202), GET `audiences/jobs`, GET `audiences/meta-status` | audiences.manage | Meta Custom Audience create + replace upload (queued); live Meta size/delivery |
| GET/POST/DELETE `api-tokens[/{id}]` | api.tokens, browser session only | personal bearer tokens; plaintext returned once on create |
| POST `contacts/{id}/erase {reason, confirm:"ERASE"}` | contacts.erase, browser session only | irreversible PII erasure; returns scrub counts |
| GET/POST `/webhooks/whatsapp/{key}` | public, signature-verified | Meta verification + events |
| GET/POST `/webhooks/meta/{key}` | public, signature-verified | Page `leadgen` webhook (Lead Ads) |
