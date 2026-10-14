# Assumptions and decisions

1. **Toolchain.** Laravel 13 on PHP ≥ 8.3 (Laravel 13's minimum); production should run 8.4. `composer.lock` was resolved for a PHP 8.3 platform so it installs on both.
2. **No SPA / no Vite.** Server-rendered shells + jQuery modules satisfy the brief's stack and the strict `'self'` CSP with zero inline code. DataTables was not used: tables are server-side paginated/sorted/filtered by the API, which DataTables would only duplicate.
3. **API auth** is the web session + CSRF (the UI is the only client). External token auth (Sanctum) is deferred to Phase 6 when there is an external consumer.
4. **Tenancy:** single database, `organization_id` on every tenant row, fail-closed global scope. Users have one organization. "Super Administrator" is organization-scoped for now; a cross-tenant platform console is out of scope until there are multiple businesses.
5. **Contacts vs leads:** an exact normalized phone/WhatsApp/email match links a new inquiry to the existing contact (not a merge). A second *open* lead on the same contact needs explicit confirmation (configurable).
6. **Phone validation** is strict: numbers that don't validate for the chosen country are rejected on manual entry and reported as import errors, never guessed. Raw input is always stored.
7. **Statuses carry a fixed "meaning"** so organizations can rename/reorder freely. Automatic moves are forward-only; an unanswered call can only reach "Contact Attempted". A won lead is treated as qualified; reopening a won lead clears its conversion.
8. **Missed follow-up** = still pending 24 h after its scheduled time (configurable `FOLLOWUP_MISSED_AFTER_HOURS`); missed tasks remain actionable.
9. **Ad metrics** use only spend in the organization's currency; no FX conversion. CAC counts ad spend only.
10. **Campaign ↔ lead attribution** for manual entry is first-touch = last-touch at creation; later touches (Phase 4 webhooks) update last-touch only. Imports may create missing campaigns by name (marked `data_source=import`).
11. **Archiving ≠ deletion.** Legal erasure/retention jobs are a Phase 6 item; suppression records survive contact merges.
12. **Default locale/currency/time zone**: PKR, Asia/Karachi, PK numbering — all per-organization settings.
13. **AI "training" is retrieval, not fine-tuning.** The knowledge base is retrieved per question; "continuous learning" means human-approved answers are added to the knowledge base. Fine-tuning would be slower to update, costlier, and still hallucinate prices.
14. **Default AI mode is "suggest".** Auto-sending is opt-in per business, defaults to knowledge-base-only, and is capped per conversation.
15. **OpenAI model is not hard-coded.** Model IDs change frequently; the assistant reads the live list from the account. DeepSeek defaults to `deepseek-v4-flash` (current as of Oct 2026).
16. **WhatsApp profile name** is used as the contact name only when the contact has no name; it is user-supplied display text, not verified identity.
17. **Interface translation** covers navigation, layout and the AI/WhatsApp/KB/analytics/profile/admin screens in Urdu and Arabic with RTL. Older CRM screens (lead table, imports, pipeline) still show English labels inside RTL layout; their strings can be added to `lang/*.json` and `public/js/i18n/*.js`.
18. **Vector search** runs in PHP over stored float32 vectors; suitable up to ~8k chunks per business before moving to a vector database.

## Phase 3 / Meta (this release)
- Rules run at most once per (rule, event). If a business wants a reminder to repeat (e.g. every day a lead stays uncontacted), they add a second timed rule with a longer delay; we deliberately do not re-fire the same rule on the same lead.
- Email is sent only when `MAIL_MAILER` is a real transport. With the default `log` mailer nothing is queued, and the profile page says so; preferences are still saved.
- The first-contact assignment rule ships disabled: enabling automatic assignment on a business whose reps are not yet set up would create tasks nobody sees.
- Meta spend is stored in the ad account currency as reported. CPL/CAC are only computed when that currency equals the organization currency; we do not convert.
- Meta's insights for the last 3 days are re-fetched every hour because Meta restates attribution; "Reload 30 days" exists for larger corrections.
- A Lead Ads submission whose phone is invalid for the organization's default country and that has no email is kept as a failed submission with the raw phone in the error, not as a contact.
- Lead `created_at` for Instant Form leads is the Meta submission time, so SLA timers and cohort reports reflect when the person actually enquired.

## Phase 5 — audiences
- Default consent policy is "not opted out" rather than "explicit consent recorded": most businesses have not captured per-channel ads consent yet, and Meta's customer-list terms put the responsibility for having the right to use the data on the advertiser. The strict policy is one click away per segment.
- Uploads carry hashed phone, email and country only. Names/city would raise match rates but are not necessary; "minimum necessary data" wins.
- Every Meta sync is a full replace (usersreplace session), not incremental add/remove, so the audience can never retain someone who has since opted out.
- Exclusion lists are CRM-side only. To exclude people inside Meta, upload them as an audience and exclude that audience in the ad set.
- Deleting a segment leaves its Meta audience in Ads Manager; remote assets are never deleted silently.
- Audience sizes shown are Meta's own approximate bounds; the CRM shows "eligible" counts, never a predicted match count.
