<?php

namespace App\Listeners\Lessons;

use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Events\Lessons\LessonStatusChanged;
use App\Notifications\Lessons\LessonCancelledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * CP7 8e (R139): the "booking cancelled" bell, both parties. One listener stands in for three separate
 * mail listeners, so it fires on exactly the union of their conditions — each branch below is named for
 * the mail listener it mirrors, and must be kept in sync with it:
 *
 * - `SendLessonCancelledMail`: a paid cancellation, `confirmed -> cancelled_by_parent`/`cancelled_by_tutor`.
 * - `SendLessonSkippedMail`: a free skip, `reserved -> cancelled_by_parent`/`cancelled_by_tutor`, with a
 *   person's own cancel note (not a machine `LessonCancelReason` value — those are slot pauses/ends and
 *   have their own slot-level email, or the two branches below, or are the deliberately silent case).
 * - `SendWeeklyChargeMails`: the tutor-no-longer-bookable branch (`reserved -> cancelled_by_tutor`,
 *   `tutor_unavailable`), and the payment-failure branch (`reserved -> cancelled_payment_failed`).
 *
 * Deliberately silent, matching the mail side's silence: a `reserved` cancellation whose machine reason is
 * `slot_paused`, `slot_ended`, `tutor_suspended` or `account_suspended` — each either has its own separate
 * email elsewhere, or (R138) is never announced as a cancellation reason to either party.
 */
class RecordLessonCancelledNotification implements ShouldQueue
{
    public function handle(LessonStatusChanged $event): void
    {
        $lesson = $event->lesson;
        $toCancelled = in_array($event->to, [LessonStatus::CancelledByParent, LessonStatus::CancelledByTutor], true);

        $paidCancellation = $event->from === LessonStatus::Confirmed && $toCancelled;

        $freeSkip = $event->from === LessonStatus::Reserved && $toCancelled
            && ! LessonCancelReason::isReserved($lesson->cancel_reason);

        $tutorUnavailable = $event->from === LessonStatus::Reserved
            && $event->to === LessonStatus::CancelledByTutor
            && $lesson->cancel_reason === LessonCancelReason::TutorUnavailable->value;

        $paymentFailed = $event->from === LessonStatus::Reserved
            && $event->to === LessonStatus::CancelledPaymentFailed;

        if (! $paidCancellation && ! $freeSkip && ! $tutorUnavailable && ! $paymentFailed) {
            return;
        }

        $lesson->learner->account->notify(new LessonCancelledNotification($lesson));
        $lesson->tutorProfile->user->notify(new LessonCancelledNotification($lesson));
    }
}
