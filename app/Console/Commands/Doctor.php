<?php

namespace App\Console\Commands;

use App\Support\Ops\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Production readiness checks. Exit code 1 when anything is a FAIL, so it can gate a deploy. */
class Doctor extends Command
{
    protected $signature = 'leadflow:doctor {--json}';

    protected $description = 'Check production configuration, services and background processing';

    public function handle(): int
    {
        $checks = self::checks();
        if ($this->option('json')) {
            $this->line(json_encode($checks, JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $c) {
                $tag = ['ok' => '<info>OK  </info>', 'warn' => '<comment>WARN</comment>', 'fail' => '<error>FAIL</error>'][$c['status']];
                $this->line(" $tag  {$c['name']}: {$c['detail']}");
            }
        }

        return collect($checks)->contains('status', 'fail') ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, array{key:string,name:string,status:string,detail:string}> */
    public static function checks(): array
    {
        $out = [];
        $add = function (string $key, string $name, string $status, string $detail) use (&$out) {
            $out[] = compact('key', 'name', 'status', 'detail');
        };
        $prod = app()->environment('production');

        $add('env', 'Environment', $prod ? 'ok' : 'warn', app()->environment().($prod ? '' : ' (set APP_ENV=production for live use)'));
        $add('debug', 'Debug mode', config('app.debug') ? ($prod ? 'fail' : 'warn') : 'ok', config('app.debug') ? 'APP_DEBUG=true exposes stack traces' : 'off');
        $add('key', 'Application key', strlen((string) config('app.key')) > 20 ? 'ok' : 'fail', strlen((string) config('app.key')) > 20 ? 'set (back it up — it encrypts integration secrets)' : 'missing: php artisan key:generate');
        $https = str_starts_with((string) config('app.url'), 'https://');
        $add('https', 'APP_URL uses HTTPS', $https ? 'ok' : ($prod ? 'fail' : 'warn'), config('app.url'));
        $add('cookie', 'Secure session cookie', config('session.secure') ? 'ok' : ($prod ? 'fail' : 'warn'), config('session.secure') ? 'SESSION_SECURE_COOKIE=true' : 'SESSION_SECURE_COOKIE=false — cookies sent over plain HTTP');
        $add('session', 'Session driver', in_array(config('session.driver'), ['database', 'redis'], true) ? 'ok' : 'warn', config('session.driver'));
        $add('argon', 'Password hashing', config('hashing.driver') === 'argon2id' ? 'ok' : 'warn', config('hashing.driver'));

        try {
            DB::select('select 1');
            $add('db', 'Database', 'ok', config('database.default').' reachable'.(config('database.default') === 'sqlite' && $prod ? ' (SQLite is not recommended for production)' : ''));
        } catch (\Throwable $e) {
            $add('db', 'Database', 'fail', $e->getMessage());
        }
        try {
            $ran = DB::table('migrations')->count();
            $files = count(glob(database_path('migrations/*.php')));
            $add('migrations', 'Migrations', $ran >= $files ? 'ok' : 'fail', "$ran of $files applied");
        } catch (\Throwable $e) {
            $add('migrations', 'Migrations', 'fail', $e->getMessage());
        }

        $q = config('queue.default');
        $add('queue', 'Queue connection', $q === 'sync' ? ($prod ? 'fail' : 'warn') : 'ok', $q.($q === 'sync' ? ' — webhooks and AI replies would run inline; use database or redis with a worker' : ''));
        $w = Heartbeat::age('worker');
        $add('worker', 'Queue worker', $w === null ? 'fail' : ($w > 900 ? 'fail' : 'ok'), $w === null ? 'no heartbeat yet (start: php artisan queue:work; the scheduler sends a heartbeat job every 5 min)' : "last heartbeat {$w}s ago");
        $s = Heartbeat::age('scheduler');
        $add('scheduler', 'Scheduler (cron)', $s === null ? 'fail' : ($s > 180 ? 'fail' : 'ok'), $s === null ? 'never ran — add the schedule:run cron entry' : "last run {$s}s ago");
        try {
            $failed = DB::table('failed_jobs')->count();
            $add('failed_jobs', 'Failed jobs', $failed ? 'warn' : 'ok', (string) $failed);
        } catch (\Throwable) {
        }

        $mail = config('mail.default');
        $add('mail', 'Mail transport', in_array($mail, ['log', 'array'], true) ? 'warn' : 'ok', $mail.(in_array($mail, ['log', 'array'], true) ? ' — email notifications disabled' : ''));
        $add('cache', 'Cache driver', config('cache.default') === 'array' ? 'warn' : 'ok', config('cache.default'));
        foreach (['storage/app/private', 'storage/logs', 'storage/framework/cache', 'bootstrap/cache'] as $dir) {
            $p = base_path($dir);
            $add('w_'.md5($dir), "Writable: $dir", is_dir($p) && is_writable($p) ? 'ok' : 'fail', is_dir($p) ? (is_writable($p) ? 'yes' : 'not writable by the PHP user') : 'missing');
        }
        $add('public_env', '.env outside web root', file_exists(public_path('.env')) ? 'fail' : 'ok', file_exists(public_path('.env')) ? 'public/.env exists!' : 'ok');
        $add('vendor_dev', 'Dev dependencies', class_exists(\PHPUnit\Framework\TestCase::class) && $prod ? 'warn' : 'ok', class_exists(\PHPUnit\Framework\TestCase::class) ? 'installed (use composer install --no-dev in production)' : 'not installed');
        $add('php', 'PHP version', version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'fail', PHP_VERSION);
        foreach (['pdo', 'mbstring', 'intl', 'zip', 'sodium', 'openssl', 'curl'] as $ext) {
            if (! extension_loaded($ext)) {
                $add('ext_'.$ext, "PHP extension $ext", 'fail', 'missing');
            }
        }
        $last = \App\Support\Ops\ErrorMonitor::last();
        $add('errors', 'Last application error', $last ? 'warn' : 'ok', $last ? $last['class'].' at '.$last['at'] : 'none recorded');
        $backups = Storage::disk('local')->exists('backups') ? collect(Storage::disk('local')->files('backups'))->filter(fn ($f) => str_ends_with($f, '.zip'))->sortDesc()->first() : null;
        $add('backup', 'Latest backup', $backups ? 'ok' : 'warn', $backups ? basename($backups) : 'none yet — schedule php artisan leadflow:backup');

        return $out;
    }
}
