<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// One server, one run at a time (R36 e): a second scheduler host or a slow run must not send the same notices twice.
Schedule::command('tutors:check-permits')->dailyAt('06:00')->onOneServer()->withoutOverlapping();

// Sweeps lessons left in pending_payment with no captured payment (CP3 3c) — must not double-expire
// or race a concurrent capture, hence onOneServer + withoutOverlapping alongside the command's own
// "unpaid" predicate.
Schedule::command('lessons:expire-unpaid')->everyMinute()->onOneServer()->withoutOverlapping();

// Weekly-slot generation (CP4 4c, R98): extends each active slot to the horizon and ends slots past
// their end date. Idempotent by itself (generated_until, insertOrIgnore, the (slot, starts_at)
// unique index); onOneServer + withoutOverlapping only stop a second worker starting.
Schedule::command('recurring:generate')->dailyAt('05:00')->onOneServer()->withoutOverlapping();

// 24h/1h pre-lesson reminders (CP3 step 5, PRD line 160). The per-row
// `reminder_*_sent_at IS NULL` claim inside the command is the actual
// idempotency guard; onOneServer + withoutOverlapping only stop a second
// worker starting, not what makes one harmless if it does.
Schedule::command('lessons:send-reminders')->everyMinute()->onOneServer()->withoutOverlapping();
