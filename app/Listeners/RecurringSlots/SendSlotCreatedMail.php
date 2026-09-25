<?php

namespace App\Listeners\RecurringSlots;

use App\Events\RecurringSlots\RecurringSlotCreated;
use App\Mail\RecurringSlots\RecurringSlotCreatedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendSlotCreatedMail implements ShouldQueue
{
    use ResolvesSlotRecipients;

    public function handle(RecurringSlotCreated $event): void
    {
        foreach ([$this->parentOf($event->slot), $this->tutorOf($event->slot)] as $recipient) {
            if ($recipient !== null) {
                Mail::to($recipient)->send(new RecurringSlotCreatedMail($event->slot, $recipient));
            }
        }
    }
}
