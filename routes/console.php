<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('followups:mark-missed')->hourly()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('model:prune')->daily();
