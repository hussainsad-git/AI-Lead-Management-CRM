# Deployment guide

Target: PHP 8.3/8.4 + MySQL 8.4 on a VPS or standard PHP host. Document root is `public/`; everything else, including `.env` and `storage/`, lives outside it.

## 1. Server packages
```bash
apt install php8.3-{fpm,cli,mysql,mbstring,intl,zip,curl,xml,bcmath,gd} nginx mysql-server supervisor unzip
# argon2 is built into PHP ≥ 7.2 on Debian/Ubuntu builds; `php -r 'echo PASSWORD_ARGON2ID;'` must print argon2id
```

## 2. nginx (recommended)
```nginx
server {
    listen 443 ssl http2;
    server_name crm.example.com;
    root /var/www/leadflow/public;
    index index.php;
    ssl_certificate     /etc/letsencrypt/live/crm.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/crm.example.com/privkey.pem;
    client_max_body_size 25m;                      # spreadsheet / KB uploads
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_read_timeout 120;
    }
    location ~ /\.(?!well-known) { deny all; }
    location ~* \.(css|js|woff2?|png|svg|ico)$ { expires 30d; add_header Cache-Control "public, immutable"; }
}
server { listen 80; server_name crm.example.com; return 301 https://$host$request_uri; }
```
The application sets CSP, HSTS, X-Frame-Options, nosniff, Referrer-Policy and Permissions-Policy itself; do not add conflicting headers in nginx.

**Apache**: point the vhost at `public/`, `AllowOverride All` (the shipped `.htaccess` handles rewrites), enable `mod_rewrite` and `mod_headers`.

**Behind Cloudflare / a load balancer**: `APP_URL=https://…` and in `bootstrap/app.php` add `$middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);` (or the proxy's IPs), otherwise `request()->isSecure()` is false and HSTS/secure cookies misbehave.

## 3. PHP-FPM
`php.ini` for the FPM pool: `upload_max_filesize=25M`, `post_max_size=26M`, `memory_limit=256M`, `max_execution_time=120`, `expose_php=Off`, `opcache.enable=1`, `opcache.validate_timestamps=0` (re-run `php artisan optimize` and reload FPM on deploy).

## 4. Application
```bash
cd /var/www/leadflow
composer install --no-dev --optimize-autoloader
cp .env.example .env && nano .env            # APP_URL (https), DB_*, MAIL_*, APP_ENV=production, APP_DEBUG=false
php artisan key:generate
php artisan migrate --force
php artisan leadflow:install                 # first business + Super Administrator (becomes platform admin)
php artisan optimize                         # config/route/view/event caches
chown -R www-data:www-data storage bootstrap/cache && chmod -R ug+rwX storage bootstrap/cache
```

## 5. Background processing (mandatory)
Cron (as `www-data`):
```cron
* * * * * cd /var/www/leadflow && php artisan schedule:run >> /dev/null 2>&1
```
Supervisor:
```ini
[program:leadflow-worker]
command=php /var/www/leadflow/artisan queue:work database --sleep=3 --tries=1 --max-time=3600 --memory=256
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=3600
stdout_logfile=/var/www/leadflow/storage/logs/worker.log
```
Scheduled work: scheduler heartbeat (every minute), worker heartbeat (5 min), `automation:scan` (5 min), `meta:sync` (hourly), `followups:mark-missed` (hourly), `leads:rescore` (hourly), `leadflow:backup` (01:30), `leadflow:retention` (02:30), `audiences:sync` (03:10), queue pruning (daily).

## 6. Deploy / upgrade
```bash
git pull  (or unzip the release over the directory, keeping .env and storage/)
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan leadflow:doctor          # must exit 0
```
Bump `ASSET_VERSION` in `.env` when `public/js` or `public/css` changed so browsers fetch the new files.

## 7. Go-live checklist
- [ ] `php artisan leadflow:doctor` shows no FAIL; WARN only for things you accept.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` https, `SESSION_SECURE_COOKIE=true`.
- [ ] `.env` backed up to a secrets manager (APP_KEY!), file mode `640`, owned by the deploy user, readable by `www-data`.
- [ ] MySQL user limited to the `leadflow` database; `bind-address=127.0.0.1` unless remote access is needed.
- [ ] Mail transport configured and a test notification email received (Profile → Email notifications).
- [ ] Queue worker and scheduler heartbeats green on Platform Admin.
- [ ] First backup created and copied off-server; restore drill completed into a scratch database.
- [ ] Integrations: WhatsApp webhook verified from Meta; Meta Marketing test connection green; AI provider connected.
- [ ] Error monitoring: platform admins receive the test notification (`php artisan tinker` → `report(new RuntimeException('test'))` — or any real error), optional `ERROR_WEBHOOK_URL` set.
- [ ] Retention settings reviewed per business (Settings → Organization → Data retention).
- [ ] `composer audit` run on the build machine (see docs/SECURITY.md) and no unresolved high/critical advisories.
- [ ] Log rotation: `storage/logs` uses the daily channel (14 days); add logrotate for `worker.log`.
