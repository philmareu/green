<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('plaid:sync')->hourly()->withoutOverlapping();

// Deletes Plaid activity log entries older than PlaidLog::RETENTION_DAYS.
Schedule::command('model:prune')->daily();

// Reminders on bills and income, in the morning in APP_TIMEZONE.
Schedule::command('reminders:send')->dailyAt('08:00');
