<?php

namespace App\Listeners\Lessons;

use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Events\Lessons\LessonChargeFailed;
use App\Events\Lessons\LessonStatusChanged;
use App\Mail\Payments\WeeklyLessonCancelledForPaymentMail;
use App\Mail\Payments\WeeklyLessonChargedMail;
use App\Mail\Payments\WeeklyLessonChargeFailedMail;
use App\Mail\Payments\WeeklyLessonTutorUnavailableMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * The auto-charge emails (R102, R104), all to the account holder and never the learner
 * (invariant 7): charge succeeded (`reserved -> confirmed`), charge failed with the retry time,
 * lesson cancelled for payment failure (`reserved -> cancelled_payment_failed`, also the tutor), and
 * lesson cancelled because the tutor was no longer bookable (`reserved -> cancelled_by_tutor`, also the tutor).
 */
class SendWeeklyChargeMails implements ShouldQueue
{
    public function handleStatusChanged(LessonStatusChanged $event): void
    {
        if ($event->from !== LessonStatus::Reserved) {
            return;
        }

        $lesson = $event->lesson;

        // A weekly lesson cancelled because its tutor was no longer bookable when it fell due (R104):
        // the parent and the tutor each get their own wording. Any other free skip is `SendLessonSkippedMail`'s.
        if ($event->to === LessonStatus::CancelledByTutor) {
            if ($lesson->cancel_reason === LessonCancelReason::TutorUnavailable->value) {
                $parent = $lesson->learner->account;
                $tutor = $lesson->tutorProfile->user;

                Mail::to($parent)->send(new WeeklyLessonTutorUnavailableMail($lesson, $parent));
                Mail::to($tutor)->send(new WeeklyLessonTutorUnavailableMail($lesson, $tutor));
            }

            return;
        }

        $parent = $lesson->learner->account;

        if ($event->to === LessonStatus::Confirmed) {
            Mail::to($parent)->send(new WeeklyLessonChargedMail($lesson, $parent));

            return;
        }

        if ($event->to === LessonStatus::CancelledPaymentFailed) {
            Mail::to($parent)->send(new WeeklyLessonCancelledForPaymentMail($lesson, $parent));

            $tutor = $lesson->tutorProfile->user;
            Mail::to($tutor)->send(new WeeklyLessonCancelledForPaymentMail($lesson, $tutor));
        }
    }

    public function handleChargeFailed(LessonChargeFailed $event): void
    {
        $parent = $event->lesson->learner->account;

        Mail::to($parent)->send(new WeeklyLessonChargeFailedMail($event->lesson, $parent, $event->retryAt));
    }
}
