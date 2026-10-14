# Switching sessions, cache and queue to Redis

The default install runs sessions, cache and the queue on MySQL (`database` drivers). That is correct for one server with a couple of workers. Move to Redis when any of these is true: more than ~4 queue workers, more than one web node, a `jobs` table that grows faster than workers drain it, or lock contention visible as slow `cache_locks` queries. Redis gives O(1) queue pops, atomic locks and no table bloat; nothing in the application changes — only drivers.

## What LeadFlow puts on each store

| Store | Contents | Loss tolerance |
|---|---|---|
| session | logged-in sessions, 2FA pending state, CSRF tokens | users re-login; nothing else lost |
| cache | heartbeats (`Heartbeat`), rate-limiter counters, error-monitor fingerprints, Meta approx audience counts | self-healing |
| queue | webhook processing, imports, AI replies, Meta/audience syncs, notification emails, automation runs | **must not be lost** — jobs are not reconstructable from Redis alone |
| locks | `ShouldBeUnique` jobs (`SyncAudienceToMeta`, `RespondToConversation`), scheduler `onOneServer` | expire on their own (`uniqueFor` > `timeout`) |

Queue and locks live on the `default` Redis connection (DB 0); cache and sessions on the `cache` connection (DB 1). Keep that split.

## 1. Server

```bash
apt install redis-server php8.3-redis        # phpredis extension; predis is not required
php -m | grep -i redis                       # must print "redis"
```

`/etc/redis/redis.conf` for a single shared instance:

```
bind 127.0.0.1 ::1
requirepass <long-random-password>
maxmemory 512mb
maxmemory-policy noeviction        # NEVER allkeys-* on an instance that holds queues or locks
appendonly yes                     # queued jobs survive a restart
appendfsync everysec
```

`maxmemory-policy` is per instance, not per DB. If you want LRU eviction for the cache, run two instances (or a managed cache + a managed queue store) and point `REDIS_CACHE_*` at the second one. With `noeviction`, a full instance makes writes fail loudly (`OOM command not allowed`) instead of silently dropping jobs — that is the behaviour you want; `leadflow:doctor` flags `allkeys-*` as FAIL. Scheduled commands are declared `onOneServer()`, so several nodes can run the cron entry safely once the cache is shared.

## 2. `.env`

```dotenv
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=<same as requirepass>
REDIS_DB=0                 # queue + locks
REDIS_CACHE_DB=1           # cache + sessions
REDIS_PREFIX=leadflow:     # one short prefix; the default doubles up ("app-database-app-cache-")
REDIS_PERSISTENT=true      # reuse connections under PHP-FPM
```

Optional: `REDIS_URL=tls://user:pass@host:6380` for managed providers; `REDIS_QUEUE=default` if you later split queues.

## 3. Cut-over (zero data loss)

1. `php artisan down --secret=<token>` so no new jobs are enqueued by web requests; the scheduler keeps running.
2. Drain the database queue: `php artisan queue:work database --stop-when-empty` (run until it exits; check `select count(*) from jobs` = 0 and nothing in `failed_jobs` you want to retry).
3. Edit `.env` as above, then `php artisan config:cache`.
4. Switch the Supervisor program to the Redis connection (below), `supervisorctl reread && supervisorctl update`, confirm `supervisorctl status` shows the new workers RUNNING.
5. `php artisan leadflow:doctor` → the `Redis` line must be OK and `Queue worker` OK within 5 minutes (next heartbeat).
6. `php artisan up`.

Sessions are not migrated: everyone is logged out once. Old `sessions`, `cache`, `cache_locks`, `jobs` tables can stay (harmless) or be truncated after a week.

## 4. Supervisor

```ini
[program:leadflow-worker]
command=php /var/www/leadflow/artisan queue:work redis --sleep=1 --tries=1 --max-time=3600 --memory=256 --backoff=10
user=www-data
numprocs=4
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=3600
stdout_logfile=/var/www/leadflow/storage/logs/worker.log
```

`--tries=1` stays: jobs own their retry semantics (Meta/WhatsApp calls are idempotent per event, imports are batch-resumable). Scale `numprocs` with CPU; each worker holds one MySQL connection.

Laravel Horizon is compatible (`composer require laravel/horizon`, replace the program with `php artisan horizon`) but is not bundled: it adds a dashboard route that must be gated behind `horizon.view` → `settings.manage`, and the brief required no extra runtime dependencies. If you add it, protect the `/horizon` route with the existing `auth` and `2fa` middleware.

## 5. Rollback

Reverse the three `.env` lines, `php artisan config:cache`, restore the `database` Supervisor program. Jobs already in Redis must be drained first the same way (`queue:work redis --stop-when-empty`).

## 6. Verification record

Executed in the delivery sandbox against Redis 7.0.15 (phpredis):

- Full test suite on `SESSION_DRIVER=redis CACHE_STORE=redis`: 94 passed / 2376 assertions (same as the array drivers).
- Live: login → session key in DB 1; `schedule:run` enqueued `WorkerHeartbeat` to DB 0; `queue:work redis --once` consumed it; `leadflow:doctor` reported `Session driver: redis`, `Queue connection: redis`, `Queue worker: last heartbeat 1s ago`, `Redis: default v7.0.15 maxmemory-policy=noeviction; cache v7.0.15`.
- Switching the instance to `allkeys-lru` made doctor emit `FAIL Redis eviction policy`.

Two defects surfaced by the Redis run and fixed in the same change: unique jobs had no `uniqueFor`, so a worker killed mid-run (SIGKILL/OOM) would have left the segment un-syncable until a cache flush; and the test harness assumed per-process stores (fixed in `tests/TestCase::setUp`, which now flushes both Redis DBs). Not verified here: Redis Cluster/Sentinel topologies and TLS to a managed provider.
