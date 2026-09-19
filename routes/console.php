<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// One server, one run at a time (R36 e): a second scheduler host or a slow run must not send the same notices twice.
Schedule::command('tutors:check-permits')->dailyAt('06:00')->onOneServer()->withoutOverlapping();
