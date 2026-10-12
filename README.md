# LeadFlow CRM

Lead management for businesses that run Facebook/Instagram ads with WhatsApp as the main channel: one place for leads from ads, WhatsApp, forms, walk-ins and spreadsheets; a sales pipeline; calls, callbacks and appointments; honest campaign cost metrics; and auditable, permissioned access.

**Status:** Phases 1–2 (foundation + core CRM) are implemented and tested. WhatsApp Cloud API, Meta Marketing API, automation and audience sync are later phases and are shown in the app as *not configured / planned* — nothing is simulated. See `docs/ARCHITECTURE.md`.

## Stack

| Layer | Choice |
|---|---|
| Runtime | PHP 8.3+ (8.4 recommended), Laravel 13 |
| Database | MySQL 8.4 (SQLite works for local dev/tests) |
| Frontend | Blade shells + jQuery 4 modules, Bootstrap 5.3, Bootstrap Icons, Chart.js 4, SortableJS — all vendored in `public/vendor`, no build step |
| Files | PhpSpreadsheet (XLSX), libphonenumber (E.164) |
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
# admin@demo.test / Password123!  (also manager@, rep1@, rep2@, marketing@, analyst@demo.test)
```

Run tests: `php vendor/bin/phpunit` (37 tests, in-memory SQLite).

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
   Jobs: mark missed follow-ups (hourly), prune failed jobs (daily).
6. **Queue worker** — imports depend on it. Supervisor example:
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
7. Upgrades: pull code → `composer install --no-dev -o` → `php artisan migrate --force` → re-cache config/routes/views → `php artisan queue:restart` → bump `ASSET_VERSION`.

## Backup and restore

What to back up: the MySQL database (all CRM data, audit log, consent ledger), `storage/app/private/imports` (original uploaded spreadsheets — optional, retained for audit), and `.env` (store separately and encrypted; it holds `APP_KEY`, which encrypts sessions and, from Phase 4, integration secrets — **losing it makes encrypted values unrecoverable**).

```bash
# nightly (cron) — consistent InnoDB snapshot, compressed, 14-day retention
mysqldump --single-transaction --routines --triggers -u leadflow -p leadflow | gzip > /backups/leadflow-$(date +%F).sql.gz
find /backups -name 'leadflow-*.sql.gz' -mtime +14 -delete

# restore into an empty database
gunzip < /backups/leadflow-2026-10-12.sql.gz | mysql -u leadflow -p leadflow
php artisan migrate --force   # applies any migrations newer than the backup
```

Copy backups off-server (S3/Backblaze/another host) and test a restore at least quarterly. Personal data in backups is subject to the same retention policy as the live database.

## Security notes

Argon2id passwords; session regenerated on login; HttpOnly/Secure/SameSite cookies; CSRF on every state-changing request (the JSON API uses the session, not tokens); login throttled (5/min per email+IP); strict CSP (`'self'` only, no inline script/style); tenant scoping on every model with fail-closed default; permissions enforced server-side and record visibility (own/team/all) applied to lists, detail, pipeline, follow-ups, dashboard and exports; audited exports, merges, opt-outs, role/user/settings changes; CSV exports neutralise formula injection; uploaded spreadsheet formulas are never evaluated. Run `composer audit` in CI for dependency advisories.

## Documentation

- `docs/ARCHITECTURE.md` — architecture, data model, ER diagram, navigation map, phase checklist
- `docs/API.md` — REST endpoints, response/error format, metric definitions
- `docs/QA_CHECKLIST.md` — manual test script
- `docs/INTEGRATIONS.md` — what Phase 4 will need from your Meta/WhatsApp accounts
- `docs/ASSUMPTIONS.md` — decisions made where the brief was open
