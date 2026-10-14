<?php

namespace App\Support\Ops;

use Illuminate\Support\Facades\Cache;

/** Liveness markers for the scheduler and the queue worker, read by the doctor and the admin page. */
class Heartbeat
{
    public static function beat(string $name): void
    {
        Cache::put("heartbeat:$name", time(), now()->addDays(2));
    }

    /** @return int|null seconds since the last beat */
    public static function age(string $name): ?int
    {
        $t = Cache::get("heartbeat:$name");

        return $t ? max(0, time() - (int) $t) : null;
    }
}
