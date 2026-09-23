<?php

use App\Console\Commands\ExpireUnpaidLessons;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Models\Lesson;
use App\Models\Payment;

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
