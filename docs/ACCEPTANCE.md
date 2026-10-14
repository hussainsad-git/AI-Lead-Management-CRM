# Acceptance criteria — verification record (v1.1, 14 Oct 2026)

Evidence types: **T** = automated test (`php vendor/bin/phpunit`, 91 tests), **B** = scripted browser run (Chromium/Playwright against the demo business, desktop 1366×900 and mobile 390×844 with touch), **D** = documented procedure.

| # | Criterion | Evidence |
|---|---|---|
| 1 | Administrator can securely log in and manage users | T `AuthAndAccessTest` (Argon2id, throttling, session rotation), `TwoFactorTest`; B Team page create/edit/reset-2FA |
| 2 | Salesperson can create, view, assign, update leads | T `LeadLifecycleTest`; B end-to-end run below (rep role) |
| 3 | Leads importable from Excel/CSV | T `DataExchangeTest` (CSV + XLSX, mapping, dry run, errors, duplicates); B import wizard |
| 4 | Duplicates detected and reviewed safely | T `LeadLifecycleTest` (strong match links, weak never auto-merges), merge is permissioned + audited |
| 5 | Lead moves through pipeline with status history | T status transitions, reasons required for lost/DNC; B run shows `Stage history` tab populated (New → Contact Attempted → Responded → Interested → Qualified → Won) |
| 6 | Salesperson can record calls and schedule callbacks | T `FollowUpService` tests; B "Callback requested" creates the task, "Interested" advances the stage |
| 7 | Overdue follow-ups on the dashboard | T `DashboardMetricsTest`; B dashboard tile + Calls & Follow-ups overdue view |
| 8 | Permissions enforced on the backend | T every suite asserts 403/404 for the wrong role; `AuthAndAccessTest` cross-tenant 404s |
| 9 | Important lead activities auditable | T audit entries for exports, merges, erasure, tokens, integrations, retention, automation; timeline events for every lead change |
| 10 | Dashboard figures reflect database data | T `DashboardMetricsTest` computes expected values from seeded rows; ad metrics show "unavailable" without spend |
| 11 | Webhooks authenticated, validated, idempotent | T `WhatsAppAiTest`, `MetaIntegrationTest` (verify token, HMAC, duplicate delivery, retries) |
| 12 | Integrations show honest connection/error states | T expired-token tests; B Integrations page shows `Error` with the provider message when the sandbox cannot reach Graph — never "Connected" |
| 13 | Customer information not fabricated from anonymous clicks | T `MetaIntegrationTest::test_submission_without_contact_details_never_fabricates_a_contact`; `campaign_events` holds aggregates only |
| 14 | Retargeting exports respect authorization, eligibility, opt-out | T `AudienceTest` (suppressed/consent/excluded/no-identifier reasons, export permission, hashed upload excludes opt-outs) |
| 15 | Installable from the documented instructions | D README + docs/DEPLOYMENT.md; `php artisan leadflow:doctor` gates go-live |
| 16 | Automated tests cover critical workflows | T 91 tests / 723 assertions across auth, tenancy, leads, imports, exports, automation, integrations, audiences, 2FA, tokens, erasure, retention, backup |

## End-to-end browser run (rep role)
Script: create lead → log *No answer* → log *Callback requested* (task created for +2 h) → log *Interested* (stage auto-advances to Interested) → set *Qualified* → set *Won*. Verified on desktop and mobile: timeline shows every step with actor and time, stage history tab lists all transitions, won closes the pending callback, no console errors, no horizontal overflow at 390 px. Screenshots are kept in the QA artefacts of the release.

## Performance review (25,023 synthetic leads, SQLite, `php artisan serve`, single process)
`php artisan db:seed --class=LoadTestSeeder` creates a separate "Load Test" business (never run on production). Response times for a warm process, before → after the Phase 6/v1.1 review:

| Endpoint | Before | After |
|---|---|---|
| `analytics?range=last_30_days` | 5.4 s | 0.24 s (funnels rewritten as GROUP BY aggregates instead of loading every lead) |
| `dashboard?range=last_30_days` | 0.98 s | 0.73 s |
| `pipeline` (9 open columns × 40 cards) | 0.61 s | 0.49 s |
| `leads` list, search, filtered page 3 | 0.13–0.21 s | unchanged |
| `follow-ups?view=overdue`, `campaigns`, `audiences` | < 0.05 s | unchanged |

MySQL 8 with InnoDB and the shipped indexes is faster than this SQLite baseline for the grouped queries. The dashboard remains the heaviest page (≈35 counts per load); if it matters at larger volumes, cache its payload per user for 60 s (`Cache::remember`) — a one-line change in `DashboardController`.
