<?php

namespace App\Listeners\RecurringSlots;

use App\Enums\RecurringSlotPauseReason;
use App\Events\RecurringSlots\RecurringSlotPaused;
use App\Mail\RecurringSlots\RecurringSlotPausedAdminMail;
use App\Mail\RecurringSlots\RecurringSlotPausedMail;
use App\Support\Mail\AdminRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * One email per party when a slot is paused (R99/R102), replacing the per-lesson skip emails that
 * `SendLessonSkippedMail` no longer sends for `slot_paused` cancellations. A `payment_failed`
 * pause is the system's, so the admins are told as well (R102).
 */
class SendSlotPausedMail implements ShouldQueue
{
    use ResolvesSlotRecipients;

    public function handle(RecurringSlotPaused $event): void
    {
        foreach ([$this->parentOf($event->slot), $this->tutorOf($event->slot)] as $recipient) {
            if ($recipient !== null) {
                Mail::to($recipient)->send(new RecurringSlotPausedMail($event->slot, $recipient, $event->cancelledLessons));
            }
        }

        if ($event->slot->paused_reason === RecurringSlotPauseReason::PaymentFailed && ($admins = AdminRecipients::resolve()) !== []) {
            Mail::to($admins)->send(new RecurringSlotPausedAdminMail($event->slot, $event->cancelledLessons));
        }
    }
}
