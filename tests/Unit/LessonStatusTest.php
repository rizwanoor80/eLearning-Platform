<?php

use App\Enums\LessonStatus;

// Terminal per docs/DATA_MODEL.md:250 (money/obligation reading, not
// LessonStateMachine::edges()'s graph-terminal reading — see LessonStatus::terminal()'s
// docblock). Every case is listed explicitly so a new status forces a decision here.
it('classifies every lesson status as terminal or not, per DATA_MODEL.md:250', function (LessonStatus $status, bool $expectedTerminal) {
    expect($status->isTerminal())->toBe($expectedTerminal);
})->with([
    'pending_payment' => [LessonStatus::PendingPayment, false],
    'reserved' => [LessonStatus::Reserved, false],
    'confirmed' => [LessonStatus::Confirmed, false],
    'in_progress' => [LessonStatus::InProgress, false],
    'completed' => [LessonStatus::Completed, false],
    'completed_reported' => [LessonStatus::CompletedReported, true],
    'disputed' => [LessonStatus::Disputed, false],
    'settled' => [LessonStatus::Settled, true],
    'expired' => [LessonStatus::Expired, true],
    'cancelled_by_parent' => [LessonStatus::CancelledByParent, false],
    'cancelled_by_tutor' => [LessonStatus::CancelledByTutor, true],
    'cancelled_payment_failed' => [LessonStatus::CancelledPaymentFailed, true],
    'no_show_student' => [LessonStatus::NoShowStudent, false],
    'no_show_tutor' => [LessonStatus::NoShowTutor, false],
    'no_show_both' => [LessonStatus::NoShowBoth, false],
    'provider_failure' => [LessonStatus::ProviderFailure, true],
    'refunded' => [LessonStatus::Refunded, true],
]);

it('covers every case declared on the enum, so a new case cannot be forgotten here', function () {
    expect(array_map(fn (LessonStatus $s) => $s->value, LessonStatus::cases()))
        ->toEqualCanonicalizing([
            'pending_payment', 'reserved', 'confirmed', 'in_progress', 'completed',
            'completed_reported', 'disputed', 'settled', 'expired',
            'cancelled_by_parent', 'cancelled_by_tutor', 'cancelled_payment_failed',
            'no_show_student', 'no_show_tutor', 'no_show_both', 'provider_failure', 'refunded',
        ]);
});
