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
