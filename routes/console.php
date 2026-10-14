<?php

use Illuminate\Support\Facades\Schedule;

// onOneServer(): with several web nodes each running cron, only one executes each entry (needs a shared cache: database or redis).
Schedule::command('followups:mark-missed')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily()->onOneServer();
Schedule::command('model:prune')->daily()->onOneServer();
Schedule::command('leads:rescore')->hourlyAt(15)->withoutOverlapping()->onOneServer();
Schedule::command('queue:prune-batches')->daily()->onOneServer();
Schedule::command('automation:scan')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('meta:sync')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('audiences:sync')->dailyAt('03:10')->withoutOverlapping()->onOneServer();
Schedule::command('leadflow:retention')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
Schedule::call(fn () => \App\Support\Ops\Heartbeat::beat('scheduler'))->everyMinute()->name('heartbeat');
Schedule::job(new \App\Jobs\WorkerHeartbeat)->everyFiveMinutes()->name('worker-heartbeat')->onOneServer();
Schedule::command('leadflow:backup')->dailyAt('01:30')->withoutOverlapping()->onOneServer();
