# Deploying on cPanel shared hosting

Shared hosting works for a sales team of a few people. The differences from a VPS: no Supervisor (the queue worker runs from cron), no root, and usually no Composer — so you upload a **bundle that already contains `vendor/`** (`leadflow-crm-vX.Y-cpanel.zip`, built with `composer install --no-dev`).

## 0. Check the host first (5 minutes, before uploading anything)

cPanel → **Select PHP Version** (or *MultiPHP Manager*):
- PHP **8.3 or 8.4** must be selectable. 8.2 or lower → the app will not boot; ask the host or move hosts.
- Extensions ticked: `pdo_mysql`, `mbstring`, `intl`, `zip`, `sodium`, `gd`, `bcmath`, `fileinfo`, `curl`, `openssl`, `xml`, `ctype`, `tokenizer`. (`intl` and `sodium` are the ones most often off.)
- *Options* tab: `memory_limit` ≥ 256M, `max_execution_time` ≥ 120, `upload_max_filesize`/`post_max_size` ≥ 32M (Excel imports).

cPanel → **Cron Jobs** must exist. **Terminal** (or SSH access) is strongly preferred but not required; `MySQL Database Wizard` is required. MariaDB 10.6+ or MySQL 8 is fine.

If the host fails the PHP version or extensions check, stop here — nothing below will fix that.

## 1. Database

MySQL Database Wizard → create database `<cpaneluser>_leadflow`, create user `<cpaneluser>_leadflow` with a strong password, grant **All privileges**. Note all three values.

## 2. Subdomain and document root

Domains → *Create a New Domain* → `crm.yourdomain.com`. **Untick "Share document root"** and set the document root to:

```
/home/<cpaneluser>/leadflow/public
```

This keeps `.env`, `storage/` and `vendor/` outside the web root (the app refuses to go live otherwise — `leadflow:doctor` checks it). If your cPanel doesn't allow a custom document root, use the fallback in §8.

Enable **AutoSSL** (SSL/TLS Status → Run AutoSSL) so the subdomain gets HTTPS; WhatsApp, Meta and Twilio webhooks require it.

## 3. Upload

File Manager → `/home/<cpaneluser>/` → Upload `leadflow-crm-vX.Y-cpanel.zip` → right-click → **Extract**. You get `/home/<cpaneluser>/leadflow/` with `app/`, `public/`, `vendor/`, … Delete the zip afterwards.

## 4. Configure

File Manager → `leadflow/` → copy `.env.example` to `.env` → Edit:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://crm.yourdomain.com
SESSION_SECURE_COOKIE=true

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=<cpaneluser>_leadflow
DB_USERNAME=<cpaneluser>_leadflow
DB_PASSWORD=<the password from step 1>

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database

MAIL_MAILER=smtp              # cPanel email account; see cPanel → Email Accounts → Connect Devices
MAIL_HOST=mail.yourdomain.com
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=crm@yourdomain.com
MAIL_PASSWORD=<mailbox password>
MAIL_FROM_ADDRESS=crm@yourdomain.com
MAIL_FROM_NAME="LeadFlow CRM"
```

Leave `APP_KEY` empty; the next step generates it.

## 5. Install (Terminal / SSH)

cPanel → **Terminal**:

```bash
cd ~/leadflow
php -v                                   # must print 8.3.x or 8.4.x — if not, see §8 "php binary"
php artisan key:generate --force
php artisan migrate --force
php artisan leadflow:install             # creates your business + first Super Administrator
php artisan optimize
php artisan leadflow:doctor              # fix every FAIL before go-live (cron/worker FAILs clear after step 6)
```

Do **not** run `DemoSeeder` on production (it refuses anyway).

## 6. Cron (replaces Supervisor)

cPanel → Cron Jobs → add two entries (adjust the PHP path to what `which php` printed in the Terminal, often `/usr/local/bin/php` or `/opt/cpanel/ea-php84/root/usr/bin/php`):

```
* * * * *  cd /home/<cpaneluser>/leadflow && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * *  cd /home/<cpaneluser>/leadflow && /usr/local/bin/php artisan queue:work database --stop-when-empty --max-time=50 --tries=1 --memory=128 >> storage/logs/worker.log 2>&1
```

The second entry starts a worker every minute that drains the queue and exits before the next one starts, so imports, webhooks and AI replies are processed within ~1 minute. Within 5 minutes `leadflow:doctor` shows the scheduler and worker heartbeats OK.

Backups: `leadflow:backup` runs nightly from the scheduler and writes to `storage/app/private/backups/`; `mysqldump` is available on nearly all cPanel hosts. Also keep cPanel's own backup enabled.

## 7. Upgrade to a new version

Upload the new `-cpanel.zip`, extract to a temporary folder, then in Terminal:

```bash
cd ~
php leadflow/artisan down --secret=<token>
rsync -a --exclude .env --exclude storage/ --exclude public/.htaccess leadflow-new/ leadflow/
cd leadflow && php artisan migrate --force && php artisan optimize && php artisan up
```

(No rsync? Use File Manager to copy everything except `.env` and `storage/`.) Bump `ASSET_VERSION` in `.env` when JS/CSS changed.

## 8. Fallbacks and gotchas

**No custom document root allowed.** Put the app in `/home/<cpaneluser>/leadflow` anyway, make the subdomain's document root (e.g. `public_html/crm`) contain only two things: a copy of `public/.htaccess`, and this `index.php`:

```php
<?php
// Shim: serve the app from outside the web root.
require __DIR__.'/../../leadflow/public/index.php';
```

Also copy the `public/vendor`, `public/js`, `public/css`, `public/images` folders into that document root (or symlink them if your host allows symlinks: `ln -s ~/leadflow/public/js ~/public_html/crm/js`). Never copy `.env` or `storage/` there.

**`php -v` in Terminal shows an old version.** Use the versioned binary explicitly: `/opt/cpanel/ea-php84/root/usr/bin/php artisan …` (also in the cron lines). Or add to `~/.bashrc`: `alias php=/opt/cpanel/ea-php84/root/usr/bin/php`.

**No Terminal/SSH at all.** Ask the host to enable it — it's usually a checkbox. Without it, you cannot run `key:generate`, `migrate` or `leadflow:install`; the cron entries still run but the app can't be initialised. (A web-based installer is deliberately not provided: an unauthenticated install endpoint on a public site is exactly what the security requirements forbid.)

**Webhooks return 403/404.** AutoSSL not yet issued → wait for HTTPS. ModSecurity on some hosts blocks POSTs with JSON bodies → cPanel → ModSecurity → disable for the subdomain, or ask the host to allow-list `/webhooks/`.

**Imports time out.** They run on the queue, not in the request; if the worker cron is missing they stay "queued". Check `storage/logs/worker.log`.

**Shared hosting limits.** Expect ~1–3 s dashboard loads at 10k+ leads on a busy shared server; the 25k-lead performance numbers in `ACCEPTANCE.md` assume a VPS. Email sending goes through the host's mail limits (often 100–500/hour) — fine for notifications.
