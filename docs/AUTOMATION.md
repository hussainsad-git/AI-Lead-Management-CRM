# Automation rules, reminders & notifications

## Model
A rule is **trigger → conditions → actions**, stored in `automation_rules` (`conditions` and `actions` as JSON validated against `config/automation.php`). Every run is recorded in `automation_executions` with a `dedupe_key` of `trigger:eventId`, unique per rule: a webhook retry, a re-queued job or a second scheduler pass can never repeat an action. Each execution stores per-action results (`success` / `partial` / `failed` / `skipped`) and rules keep `run_count`, `error_count`, `last_run_at`.

**Event triggers** fire from the domain services after the surrounding transaction commits (`AutomationEngine::fire`, queued as `RunAutomation`): `lead.created`, `lead.status_changed`, `lead.qualified` (first time `qualified_at` is set), `call.logged`, `lead.opted_out`. All rules of one event evaluate against the same lead snapshot, so an earlier rule's actions cannot make a later rule match an event that did not concern it.

**Timed triggers** run from `automation:scan` every 5 minutes with a bounded look-back: `lead.uncontacted` (open lead, no `first_contacted_at`, created ≥ delay ago, ≤ 7 days), `followup.overdue` (pending task ≥ delay past its time, ≤ 7 days; context carries the task priority), `lead.inactive` (`last_activity_at` ≥ delay ago, ≤ 30 days; the event id includes the activity timestamp, so a lead that goes quiet again is picked up again).

**Loop guard.** Actions that raise further events (`move_stage`, `create_task` → `call.logged` later, …) carry a depth; at `automation.max_depth` (3) the execution is recorded as `skipped` with the reason instead of running.

**Fields** (`status` by meaning, `source`, `priority`, `score_label`, `platform`, `campaign`, `assigned`, `has_pending_task`, `contact_attempts`, `unanswered_attempts`, `call_outcome`, `product_interest`, `city`, `budget_amount`, `task_priority`) with type-appropriate operators; `match: all|any`.

**Actions**: `assign_round_robin {team_id?}` (skips already-assigned leads), `assign_user`, `create_task {purpose, in_minutes, priority?}`, `notify_owner`, `notify_manager` (team manager, else admins/sales managers, never the owner twice), `notify_users`, `set_priority`, `add_tag`, `move_stage` (open stages only; closed leads are skipped), `cancel_tasks`, `send_whatsapp_template` (approved template, respects suppression and the 24-hour rules of the messaging service), `call_webhook {url, secret?}` (POST JSON to Zapier/Make/your system; see *Outbound webhooks*). Placeholders `{name} {phone} {owner} {interest} {lead_id}`.

**Safety rails that are not rules** (so a paused rule can never switch them off): opt-out cancels open tasks and blocks messaging; a requested callback always creates its task; `create_task` and `send_whatsapp_template` are refused on `lead.opted_out` rules; closed/opted-out leads never get tasks.

## Starter rules (seeded per business, editable)
| Template | Trigger | Default |
|---|---|---|
| Assign new leads and create a first-contact task | lead.created | **off** — enable once reps are set up |
| First-contact SLA missed | lead.uncontacted after `first_contact_sla_minutes` (Settings) | on |
| Escalate overdue high-priority follow-ups | followup.overdue 60 min, priority high/urgent | on |
| Lead qualified → alert the team | lead.qualified | on |
| Three unanswered calls → suggest another approach | call.logged, unanswered ≥ 3, no open follow-up | on |

## Testing a rule
*Automation Rules → ▶* picks a lead and shows each condition with the actual value and whether it passed, plus "would run". It never performs actions. Event context (call outcome, overdue task priority) is taken from the lead's latest records.

## Reminders
`Reminders::send()` (same 5-minute scan) notifies the assignee of callbacks/follow-ups due within 15 minutes and appointments within 60 minutes, once per item and scheduled time.

## Notifications & email
`NotificationService::notify()` inserts once per `(user, dedupe_key)`. If the user's email preference for the type is on (defaults in `config/leadflow.php → notification_types`) **and** `MAIL_MAILER` is a real transport, a `SendNotificationEmail` job sends `NotificationMail` and stamps `emailed_at` (claimed before sending, so retries never double-send; already-read notifications are not emailed). Users manage preferences on *My profile → Email notifications*; the page says plainly when the server has no mailer configured.

## Outbound webhooks
Action `call_webhook` POSTs `{"event":"automation.<trigger>","sent_at":…, "rule":{id,name}, "trigger", "execution_id", "context":{…}, "lead":{…as GET /api/v1/leads/{id}…}, "organization":{id,name}, "url"}`. Headers: `X-LeadFlow-Event`, `X-LeadFlow-Delivery` (uuid — use for de-duplication), `X-LeadFlow-Timestamp`, and when a secret is set `X-LeadFlow-Signature: sha256=HMAC_SHA256(secret, "<timestamp>.<raw body>")`. Verify with constant-time comparison and reject timestamps older than 5 minutes. Non-2xx or unreachable endpoints mark the action failed in the execution history (retried twice on 5xx/connection errors, never on 4xx). URLs must be https and public (SSRF guard).

Zapier: *Webhooks by Zapier → Catch Hook* → paste the URL into the action. Make: *Webhooks → Custom webhook*. Typical rules: `lead.created` → push to a Google Sheet; `lead.qualified` → Slack message; `lead.status_changed` → update an external system.
