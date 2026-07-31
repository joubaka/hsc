<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:expire-holds')->everyMinute()->withoutOverlapping();
Schedule::command('bookings:recheck-series-conflicts')->hourly()->withoutOverlapping();
Schedule::command('lighting:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('lighting:reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('lighting:health-check')->everyFifteenMinutes()->withoutOverlapping();
