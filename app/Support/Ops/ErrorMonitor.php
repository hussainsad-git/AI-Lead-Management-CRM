<?php

namespace App\Support\Ops;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Built-in error monitoring: every reported exception is counted per fingerprint and platform admins get
 * one in-app notification per fingerprint per hour. Pair with an external tracker (Sentry/Bugsnag) via
 * ERROR_WEBHOOK_URL, which receives a small JSON summary (no request bodies, no secrets).
 */
class ErrorMonitor
{
    public static function report(Throwable $e): void
    {
        try {
            $fp = substr(sha1(get_class($e).'|'.$e->getFile().'|'.$e->getLine()), 0, 16);
            $count = Cache::increment("errors:$fp:count");
            if ($count === 1) {
                Cache::put("errors:$fp:count", 1, now()->addDay());
            }
            Cache::put('errors:last', ['class' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 200), 'at' => now()->toIso8601ZuluString(), 'fingerprint' => $fp], now()->addDays(7));
            if (Cache::add("errors:$fp:notified", 1, now()->addHour())) {
                $ids = User::withoutGlobalScopes()->where('is_platform_admin', true)->where('is_active', true)->get(['id', 'organization_id']);
                foreach ($ids as $u) {
                    Notification::query()->insertOrIgnore(['organization_id' => $u->organization_id, 'user_id' => $u->id, 'type' => 'system_error',
                        'title' => 'Application error: '.class_basename($e), 'body' => mb_substr($e->getMessage(), 0, 500) ?: 'See the log for details.',
                        'url' => '/admin', 'dedupe_key' => 'err:'.$fp.':'.now()->format('YmdH'), 'created_at' => now(), 'updated_at' => now()]);
                }
                if ($url = config('leadflow.error_webhook_url')) {
                    \Illuminate\Support\Facades\Http::timeout(5)->post($url, ['app' => config('app.name'), 'env' => app()->environment(), 'class' => get_class($e),
                        'message' => mb_substr($e->getMessage(), 0, 500), 'file' => basename($e->getFile()).':'.$e->getLine(), 'fingerprint' => $fp, 'count_24h' => $count]);
                }
            }
        } catch (Throwable $inner) {
            Log::debug('ErrorMonitor failed: '.$inner->getMessage());
        }
    }

    public static function last(): ?array
    {
        return Cache::get('errors:last');
    }
}
