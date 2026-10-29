<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('plaid:sync')->hourly()->withoutOverlapping();

// Deletes Plaid activity log entries older than PlaidLog::RETENTION_DAYS.
Schedule::command('model:prune')->daily();
