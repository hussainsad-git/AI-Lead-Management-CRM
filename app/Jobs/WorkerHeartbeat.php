<?php

namespace App\Jobs;

use App\Support\Ops\Heartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Dispatched by the scheduler every 5 minutes; when it runs, a queue worker is alive. */
class WorkerHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Heartbeat::beat('worker');
    }
}
