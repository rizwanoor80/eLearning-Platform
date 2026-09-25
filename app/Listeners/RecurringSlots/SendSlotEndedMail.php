<?php

namespace App\Listeners\RecurringSlots;

use App\Enums\LessonStatus;
use App\Events\RecurringSlots\RecurringSlotEnded;
use App\Mail\RecurringSlots\RecurringSlotEndedMail;
use App\Models\Lesson;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * One email per party when a slot ends (R99/R102), replacing the per-lesson skip emails that
 * `SendLessonSkippedMail` no longer sends for `slot_ended` cancellations.
 */
class SendSlotEndedMail implements ShouldQueue
{
    use ResolvesSlotRecipients;

    public function handle(RecurringSlotEnded $event): void
    {
        $slot = $event->slot;

        $paidRemaining = Lesson::query()
            ->where('recurring_slot_id', $slot->id)
            ->where('status', LessonStatus::Confirmed)
            ->where('starts_at', '>', now())
            ->count();

        foreach ([$this->parentOf($slot), $this->tutorOf($slot)] as $recipient) {
            if ($recipient !== null) {
                Mail::to($recipient)->send(new RecurringSlotEndedMail($slot, $recipient, $event->endedBy, $event->cancelledLessons, $paidRemaining, $event->lastDay));
            }
        }
    }
}
