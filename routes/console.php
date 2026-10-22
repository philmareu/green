<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('plaid:sync')->hourly()->withoutOverlapping();
