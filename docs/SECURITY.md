# Security controls & review notes

## Authentication & sessions
- Argon2id password hashing (`config/hashing.php`); password policy ≥ 10 chars with letters and numbers; login throttled per email+IP (`throttle:login`).
- Session rotated on login and on password change; other sessions of the user are revoked on password change. Cookies: `Secure`, `HttpOnly`, `SameSite=Lax`, encrypted, database driver.
- Deactivated users/organizations are logged out by `ResolveOrganization` on the next request.
- Two-factor authentication (TOTP, RFC 6238; in-house `App\Support\Totp` + QR renderer `App\Support\Qr`, both unit-tested against reference vectors / decoded with OpenCV): per-user enrolment with QR or manual key, 10 one-time recovery codes (hashed), ±1 step window, replay protection via last-used step, 5-minute pending-login window, rate-limited verification, admin reset (`users.manage`, audited), organization-wide **Require 2FA** policy (unenrolled users are confined to their profile; API tokens unaffected).
- Personal API tokens: 51-char random, stored SHA-256 hashed, shown once, optional expiry, revocable, max 10 per user, `api.tokens` permission required. A token carries exactly its owner's permissions and organization; tokens cannot create tokens or erase contacts.

## Authorization & tenancy
- Every tenant table has `organization_id`; `OrganizationScope` (fail-closed when no tenant is bound) filters all queries; `TenantModel` guards `organization_id` from mass assignment; the client never supplies an organization id.
- Permissions are checked server-side in every controller method (`hasPermission` / `perm:` middleware) and again in services where it matters (visibility, assignment, exports). Record-level visibility via `LeadVisibility` (own / team / all) applies to lists, detail, pipeline, follow-ups, conversations, dashboard and exports; out-of-scope records return 404, not 403.
- Platform admin actions see counts only, never customer records.

## Web security
- CSRF on every cookie-authenticated state change (skipped only for bearer-token requests, which browsers cannot forge cross-site).
- CSP `default-src 'self'`, no inline script/style anywhere, all assets vendored locally; HSTS on HTTPS; `X-Frame-Options: DENY`; nosniff; referrer and permissions policies; `Cache-Control: no-store` on API responses.
- Output encoding: Blade `{{ }}` and `LF.esc()` in every JS template; `dir="auto"` message bodies rendered as text.
- Uploads: spreadsheets and KB documents validated by extension + MIME, stored under `storage/app/private` (never web-reachable), logos re-encoded and SVG rejected.
- Exports neutralise spreadsheet formula injection (`LeadExportService::neutralize`).
- Rate limits: login, `api` (per user), `sensitive` (10/min: exports, imports, credential changes, token issuance, audience sync), `messaging`, `ai`, `webhooks` (per IP).

## Secrets & integrations
- Integration credentials stored `encrypted:array` under `APP_KEY`; write-only API (responses expose only "set" + last-4 hint); `$hidden` on the model; never logged.
- Webhooks: per-business unguessable URL key, verify-token handshake, `X-Hub-Signature-256` HMAC over the raw body with the app secret, replay/duplicate protection by body hash, idempotent processing by provider ids, always-200 after acceptance so Meta does not retry while our queue does.
- Outbound webhooks (automation action): https only, public hosts only (loopback/private/reserved ranges and `.local`/`.internal` rejected at save time), no credentials in URLs, redirects not followed, 10 s timeout, HMAC-SHA256 signature over `timestamp.body` with a per-rule secret, unique delivery id. Payload = the lead as the API presents it, never internal notes or secrets.
- Outbound: TLS by default, timeouts, bounded retries with backoff on 429/5xx only, pagination caps.
- Error responses never include stack traces or secrets in production (`APP_DEBUG=false` enforced by `leadflow:doctor`); exceptions are summarised (class, message, file:line) to platform admins and the optional error webhook — never request bodies.

## Data protection
- Consent ledger (append-only), suppression records, Do Not Contact enforced in messaging, follow-ups, automation and audiences; opt-out keywords honoured on inbound WhatsApp.
- Audit log (append-only) for exports, merges, erasure, permission/role changes, integration changes, token issuance, audience uploads, retention runs.
- Erasure (`contacts.erase`): irreversible scrub of all identifying fields, message bodies, notes and AI transcripts; keeps counts/attribution and a suppression row so the person is never contacted again.
- Retention: per-organization housekeeping of webhook payloads, notifications, AI transcripts, automation history and archiving of old closed leads (never deletes people, consent or audit).
- Meta uploads: SHA-256 hashed phone/email/country only; previews mask identifiers; exports require a separate permission and are audited.

## Dependency review
`composer audit` must be run on a machine with Packagist access before each release; `.github/workflows/ci.yml` runs `composer audit --locked --no-dev` on every push and pull request, alongside the test suite on PHP 8.3/8.4 × SQLite/MySQL 8.4 and `i18n:build --check`. The build sandbox used for this delivery cannot reach Packagist (proxy returns 403), so the audit was not run here and the MySQL CI leg is unexecuted until the first push — all raw SQL in the codebase was reviewed for dialect portability (only `LOWER()`, `CASE`, `COALESCE`, `NOT EXISTS`). Pinned major versions: Laravel 13, PhpSpreadsheet 5, libphonenumber-lite 9, pdfparser 2. Front-end libraries are vendored and should be refreshed with each release (Bootstrap 5.3.x, jQuery 4.x, Chart.js 4.x, SortableJS).

## Review findings addressed in Phase 6
- Token issuance/erasure blocked for token-authenticated callers (browser session only).
- Added indexes for Phase 3–5 query patterns (first_contacted_at, last_activity_at, score_label, archived+qualified; automation execution lookups; webhook status).
- Backups no longer pass the DB password on the command line (`MYSQL_PWD` env).
- Mass assignment: all Phase 3–6 models extend `TenantModel` (guards `id`, `organization_id`); `User::$fillable` excludes `is_platform_admin`, `organization_id`, `notification_prefs` writes go through explicit `forceFill` in controllers that validated the input.

## Known limitations
- Session driver `database` is fine to ~50 concurrent users; move sessions/cache/queue to Redis beyond that.
- Hybrid KB search scans vectors in PHP (capped at 8,000 chunks); beyond that use a vector database.
