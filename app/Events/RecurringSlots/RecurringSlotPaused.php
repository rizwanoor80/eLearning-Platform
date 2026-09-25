<?php

namespace App\Events\RecurringSlots;

use App\Models\RecurringSlot;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by `PauseRecurringSlot` after its transaction commits (R99/R102). `$cancelledLessons` is the
 * number of future `reserved` lessons released free; the reason is on the slot (`paused_reason`).
 */
class RecurringSlotPaused
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly RecurringSlot $slot, public readonly int $cancelledLessons) {}
}
