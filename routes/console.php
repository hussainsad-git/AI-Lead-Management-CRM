<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('followups:mark-missed')->hourly()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('model:prune')->daily();
Schedule::command('leads:rescore')->hourlyAt(15)->withoutOverlapping();
Schedule::command('queue:prune-batches')->daily();
Schedule::command('automation:scan')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('meta:sync')->hourly()->withoutOverlapping();
Schedule::command('audiences:sync')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('leadflow:retention')->dailyAt('02:30')->withoutOverlapping();
Schedule::call(fn () => \App\Support\Ops\Heartbeat::beat('scheduler'))->everyMinute()->name('heartbeat');
Schedule::job(new \App\Jobs\WorkerHeartbeat)->everyFiveMinutes()->name('worker-heartbeat');
Schedule::command('leadflow:backup')->dailyAt('01:30')->withoutOverlapping();
