<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('followups:mark-missed')->hourly()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('model:prune')->daily();
Schedule::command('leads:rescore')->hourlyAt(15)->withoutOverlapping();
Schedule::command('queue:prune-batches')->daily();
Schedule::command('automation:scan')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('meta:sync')->hourly()->withoutOverlapping();
