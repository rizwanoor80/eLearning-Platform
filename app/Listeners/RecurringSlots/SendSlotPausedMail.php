<?php

namespace App\Listeners\RecurringSlots;

use App\Events\RecurringSlots\RecurringSlotPaused;
use App\Mail\RecurringSlots\RecurringSlotPausedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * One email per party when a slot is paused (R99/R102), replacing the per-lesson skip emails that
 * `SendLessonSkippedMail` no longer sends for `slot_paused` cancellations. 4e adds the admin as a
 * recipient for a `payment_failed` pause.
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
    }
}
