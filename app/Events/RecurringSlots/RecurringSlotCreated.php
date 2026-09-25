<?php

namespace App\Events\RecurringSlots;

use App\Models\RecurringSlot;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by `CreateRecurringSlot` after its transaction commits (R102), whoever created the slot —
 * the parent or an admin override. Emails hang off it as queued listeners.
 */
class RecurringSlotCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly RecurringSlot $slot) {}
}
