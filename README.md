# LeadFlow CRM

Lead management for businesses that run Facebook/Instagram ads with WhatsApp as the main channel: one place for leads from ads, WhatsApp, forms, walk-ins and spreadsheets; a sales pipeline; calls, callbacks and appointments; honest campaign cost metrics; and auditable, permissioned access.

**Status:** Core CRM, WhatsApp Cloud API inbox, AI assistant (OpenAI / DeepSeek) with knowledge base, auto-replies, lead qualification & scoring, analytics, multi-business admin and Urdu/Arabic (RTL) UI are implemented and tested (62 automated tests). Meta Marketing API spend sync, Lead Ads, audience segments and email notifications are still planned and shown as such — nothing is simulated. See `docs/ARCHITECTURE.md` and `docs/AI_WHATSAPP.md`.

**Suited to:** agencies, lead-generation and real-estate businesses, educational institutes, clinics, eCommerce stores, support teams and service businesses — industry presets configure the assistant for each.

## Stack

| Layer | Choice |
|---|---|
| Runtime | PHP 8.3+ (8.4 recommended), Laravel 13 |
| Database | MySQL 8.4 (SQLite works for local dev/tests) |
| Frontend | Blade shells + jQuery 4 modules, Bootstrap 5.3, Bootstrap Icons, Chart.js 4, SortableJS — all vendored in `public/vendor`, no build step |
| Files | PhpSpreadsheet (XLSX), libphonenumber (E.164), smalot/pdfparser (knowledge-base PDFs) |
| AI | OpenAI or DeepSeek (OpenAI-compatible Chat Completions), OpenAI embeddings optional |
| Messaging | WhatsApp Business Platform Cloud API (Graph API v24.0) |
| Tests | PHPUnit 12 |

Required PHP extensions: `pdo_mysql`, `mbstring`, `intl`, `zip`, `xml`, `sodium`, `gd`, and Argon2 support in `password_hash` (standard on Linux builds).

## Local setup

```bash
composer install
cp .env.example .env
# local overrides: APP_ENV=local APP_DEBUG=true SESSION_SECURE_COOKIE=false DB_CONNECTION=sqlite
touch database/database.sqlite
php artisan key:generate
php artisan migrate --seed            # permission catalogue only
php artisan leadflow:install          # creates your organization + first Super Administrator
php artisan serve
php artisan queue:work                # separate terminal — imports run on the queue
```

Synthetic demo data (local only, refuses to run in production):

```bash
php artisan db:seed --class=DemoSeeder
# admin@demo.test / Password123!  (platform admin; also manager@, rep1@, rep2@, marketing@, analyst@demo.test)
# includes synthetic WhatsApp conversations and knowledge-base content
```

Run tests: `php vendor/bin/phpunit` (62 tests, in-memory SQLite; external APIs are faked).

The first administrator created by `leadflow:install` is the platform administrator (Platform Admin page: create, suspend and monitor businesses). Others can be promoted with `users.is_platform_admin = 1`.

## Production deployment (standard PHP/MySQL host or VPS)

1. **Document root** must be `public/`. Everything else (including `.env` and `storage/`) stays outside the web root.
2. Create the database and a dedicated user:
   ```sql
   CREATE DATABASE leadflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'leadflow'@'localhost' IDENTIFIED BY '<strong password>';
   GRANT ALL PRIVILEGES ON leadflow.* TO 'leadflow'@'localhost';
   ```
3. Deploy code, then:
   ```bash
   composer install --no-dev --optimize-autoloader
   cp .env.example .env && nano .env      # DB credentials, APP_URL, mail
   php artisan key:generate
   php artisan migrate --force --seed
   php artisan leadflow:install
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   chown -R www-data:www-data storage bootstrap/cache && chmod -R ug+rwX storage bootstrap/cache
   ```
4. **HTTPS is required** (`SESSION_SECURE_COOKIE=true`). HSTS is sent automatically on HTTPS requests. If behind a load balancer/Cloudflare, configure trusted proxies (`$middleware->trustProxies(...)` in `bootstrap/app.php`).
5. **Scheduler** — one cron entry:
   ```cron
   * * * * * cd /var/www/leadflow && php artisan schedule:run >> /dev/null 2>&1
   ```
   Jobs: `automation:scan` every 5 min (SLA/overdue/inactivity rules + callback/appointment reminders), `meta:sync` hourly (campaigns, spend, Instant Form backfill), `audiences:sync` daily (segment recount + auto-sync uploads), mark missed follow-ups (hourly), rescore open leads (hourly), prune failed jobs (daily).
6. **Queue worker** — required: WhatsApp webhooks, AI replies, knowledge-base processing and imports all run on it. Without it, messages are stored but never answered, automation rules never fire and notification emails are not sent. Supervisor example:
   ```ini
   [program:leadflow-worker]
   command=php /var/www/leadflow/artisan queue:work database --sleep=3 --tries=1 --max-time=3600
   user=www-data
   numprocs=1
   autostart=true
   autorestart=true
   stopwaitsecs=3600
   stdout_logfile=/var/www/leadflow/storage/logs/worker.log
   ```
   On shared hosting without Supervisor, a cron fallback works: `* * * * * php artisan queue:work --stop-when-empty --max-time=55`.
   After each deploy: `php artisan queue:restart`.
7. Release archives: `package.sh <version>` (ships runtime directory placeholders, excludes `.env`, caches, backups, uploads and the database). After unzipping on a server, `leadflow:install` also recreates any missing `storage/` directories.
8. Translations: edit `lang/ur.json` / `lang/ar.json` (keys are the English strings) and run `php artisan i18n:build`; `php artisan i18n:build --check` and `I18nTest` catch untranslated strings.
9. Upgrades: pull code → `composer install --no-dev -o` → `php artisan migrate --force` → re-cache config/routes/views → `php artisan queue:restart` → bump `ASSET_VERSION`.

## Backup and restore

`php artisan leadflow:backup` (scheduled nightly at 01:30) writes `storage/app/private/backups/leadflow-<timestamp>.zip` containing `db/database.sql` (mysqldump with `--single-transaction`, credentials passed via the environment, never the command line) plus `files/` (uploaded spreadsheets, logos, knowledge-base documents) and a `MANIFEST.json`. The newest `BACKUP_KEEP` (default 14) archives are kept. **Copy the archives off the server** (rclone/scp to object storage) — a backup on the same disk is not a backup. `.env` is deliberately not included: it holds `APP_KEY`, which encrypts integration secrets; keep it in your secrets manager. Losing `APP_KEY` makes stored tokens unrecoverable (re-enter them on Integrations).

Restore into an empty database:
```bash
unzip leadflow-20261014-013000.zip -d /tmp/restore
mysql -u leadflow -p leadflow < /tmp/restore/db/database.sql
rsync -a /tmp/restore/files/ /var/www/leadflow/storage/app/private/
php artisan migrate --force          # applies migrations newer than the backup
php artisan leadflow:doctor          # confirm services
```
Do a restore drill into a scratch database before go-live and after major upgrades. The `docs/DEPLOYMENT.md` go-live checklist includes it.

## Acceptance & performance
See `docs/ACCEPTANCE.md` for the criterion-by-criterion verification record and the 25k-lead performance baseline (`php artisan db:seed --class=LoadTestSeeder` on a non-production database).

## Production readiness

`php artisan leadflow:doctor` (also shown on Platform Admin) checks environment, debug, keys, HTTPS, cookies, DB, migrations, queue worker and scheduler heartbeats, failed jobs, mail, cache, writable paths, PHP extensions, last error and latest backup. It exits non-zero on any FAIL so it can gate deployments. See `docs/DEPLOYMENT.md` (web server configs, go-live checklist) and `docs/SECURITY.md` (controls and review notes).

## Security notes

Argon2id passwords; session regenerated on login; HttpOnly/Secure/SameSite cookies; CSRF on every state-changing request (the JSON API uses the session, not tokens); login throttled (5/min per email+IP); strict CSP (`'self'` only, no inline script/style); tenant scoping on every model with fail-closed default; permissions enforced server-side and record visibility (own/team/all) applied to lists, detail, pipeline, follow-ups, dashboard and exports; audited exports, merges, opt-outs, role/user/settings changes; CSV exports neutralise formula injection; uploaded spreadsheet formulas are never evaluated. `composer audit` runs in CI (`.github/workflows/ci.yml`) together with the test matrix.

## Documentation

- `docs/ARCHITECTURE.md` — architecture, data model, ER diagram, navigation map, phase checklist
- `docs/API.md` — REST endpoints, response/error format, metric definitions
- `docs/QA_CHECKLIST.md` — manual test script
- `docs/AI_WHATSAPP.md` — message flow, AI guardrails, knowledge base, learning, scoring
- `docs/INTEGRATIONS.md` — step-by-step WhatsApp / OpenAI / DeepSeek setup
- `docs/ASSUMPTIONS.md` — decisions made where the brief was open
