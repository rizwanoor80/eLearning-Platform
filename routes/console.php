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

// Weekly-lesson auto-charge (CP4 4e, R101): charges each due `reserved` lesson from the saved card,
// retries at T-36h/T-24h, cancels after the last failure and pauses the slot. Idempotent by itself
// (payments unique on (lesson_id, attempt_no) and the per-attempt gateway key); onOneServer +
// withoutOverlapping only stop a second worker starting.
Schedule::command('recurring:charge')->hourly()->onOneServer()->withoutOverlapping();

// 24h/1h pre-lesson reminders (CP3 step 5, PRD line 160). The per-row
// `reminder_*_sent_at IS NULL` claim inside the command is the actual
// idempotency guard; onOneServer + withoutOverlapping only stop a second
// worker starting, not what makes one harmless if it does.
Schedule::command('lessons:send-reminders')->everyMinute()->onOneServer()->withoutOverlapping();

// Lesson-room lifecycle (CP6 7c, PRD §9). Rooms are created from T-15 min and closed at the end + 10 min;
// the settle sweep completes lessons both sides attended and refunds confirmed ones nobody attended.
// Each is idempotent by itself (a conditional UPDATE per row, the provider's create-by-name, the state
// machine's edge check on the locked row); onOneServer + withoutOverlapping only stop a second worker
// starting. The overlap lock expires after 10 minutes, not the 24-hour default, so a run that dies
// mid-way cannot silence the sweep for a day.
Schedule::command('lessons:create-rooms')->everyMinute()->onOneServer()->withoutOverlapping(10);
Schedule::command('lessons:close-rooms')->everyMinute()->onOneServer()->withoutOverlapping(10);
Schedule::command('lessons:settle-ended')->everyMinute()->onOneServer()->withoutOverlapping(10);

// Release of a lesson's escrow when its tutor never filed the report (CP6 7e, PRD §2.7): 72 h after the
// lesson ends. Exactly-once is the state machine's `completed -> completed_reported` edge on the locked
// row, shared with the report itself, so a run racing a submit, or a second run, moves nothing twice.
Schedule::command('lessons:auto-release-reports')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10);
