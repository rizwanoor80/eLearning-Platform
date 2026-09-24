<?php

use App\Console\Commands\ExpireUnpaidLessons;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Models\Lesson;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

function pendingLessonAgedMinutes(int $minutes): Lesson
{
    return Lesson::factory()->withStatus(LessonStatus::PendingPayment)->create([
        'created_at' => now()->subMinutes($minutes),
    ]);
}

it('expires a pending_payment lesson with no captured payment past the timeout', function () {
    $lesson = pendingLessonAgedMinutes(ExpireUnpaidLessons::UNPAID_TIMEOUT_MINUTES + 1);

    $this->artisan('lessons:expire-unpaid')->assertSuccessful();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Expired);
});

it('leaves a pending_payment lesson alone before the timeout elapses', function () {
    $lesson = pendingLessonAgedMinutes(ExpireUnpaidLessons::UNPAID_TIMEOUT_MINUTES - 1);

    $this->artisan('lessons:expire-unpaid')->assertSuccessful();

    expect($lesson->fresh()->status)->toBe(LessonStatus::PendingPayment);
});

it('never expires a pending_payment lesson that already has a captured payment', function () {
    $lesson = pendingLessonAgedMinutes(ExpireUnpaidLessons::UNPAID_TIMEOUT_MINUTES + 30);
    Payment::factory()->for($lesson)->create(['status' => PaymentStatus::Captured]);

    $this->artisan('lessons:expire-unpaid')->assertSuccessful();

    expect($lesson->fresh()->status)->toBe(LessonStatus::PendingPayment);
});

it('skips a lesson whose payment captures between the sweep\'s query and its row lock (R77 item 4)', function () {
    $lesson = pendingLessonAgedMinutes(ExpireUnpaidLessons::UNPAID_TIMEOUT_MINUTES + 1);
    $payment = Payment::factory()->for($lesson)->create(['status' => PaymentStatus::Pending]);

    // The sweep's own WHERE clause saw no captured payment (it's still `pending` above) —
    // this listener fires on `lazyById()`'s own listing query (the outer, un-locked
    // snapshot), capturing the payment right after it but before the row lock, so the
    // post-lock re-check — not the already-stale snapshot — is what has to catch it.
    // It must NOT fire on the lock query itself: that runs inside `transition()`'s own
    // `DB::transaction()`, and this test's abort-the-edge exception would roll a write
    // made there straight back out, along with everything else in that transaction.
    $fired = false;

    DB::listen(function ($query) use ($payment, &$fired): void {
        if (! $fired && str_contains($query->sql, '"lessons"') && ! str_contains($query->sql, 'for update')) {
            $fired = true;
            $payment->update(['status' => PaymentStatus::Captured]);
        }
    });

    $this->artisan('lessons:expire-unpaid')->assertSuccessful();

    expect($lesson->fresh()->status)->toBe(LessonStatus::PendingPayment)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Captured);
});

it('expires a pending_payment lesson whose only payment attempt failed', function () {
    $lesson = pendingLessonAgedMinutes(ExpireUnpaidLessons::UNPAID_TIMEOUT_MINUTES + 1);
    Payment::factory()->for($lesson)->failed()->create();

    $this->artisan('lessons:expire-unpaid')->assertSuccessful();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Expired);
});

it('is a no-op the second time it runs', function () {
    pendingLessonAgedMinutes(ExpireUnpaidLessons::UNPAID_TIMEOUT_MINUTES + 1);
    pendingLessonAgedMinutes(ExpireUnpaidLessons::UNPAID_TIMEOUT_MINUTES - 5);

    $this->artisan('lessons:expire-unpaid')->assertSuccessful();
    $firstRunExpiredCount = Lesson::query()->where('status', LessonStatus::Expired)->count();

    $this->artisan('lessons:expire-unpaid')->assertSuccessful();
    $secondRunExpiredCount = Lesson::query()->where('status', LessonStatus::Expired)->count();

    expect($firstRunExpiredCount)->toBe(1)
        ->and($secondRunExpiredCount)->toBe(1);
});
