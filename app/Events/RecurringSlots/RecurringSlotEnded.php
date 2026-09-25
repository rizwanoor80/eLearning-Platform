<?php

namespace App\Events\RecurringSlots;

use App\Enums\Role;
use App\Models\RecurringSlot;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by `EndRecurringSlot` after its transaction commits (R99/R102). `$cancelledLessons` is the
 * number of `reserved` lessons released free; for a tutor's end (`Role::Tutor`) the slot stays open
 * through `end_effective_on`, carried as `$lastDay` (`Y-m-d`) because the queued email re-reads the slot
 * and a parent may end it before that runs. One email per party replaces the per-lesson skip emails.
 */
class RecurringSlotEnded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly RecurringSlot $slot,
        public readonly int $cancelledLessons,
        public readonly Role $endedBy,
        public readonly ?string $lastDay = null,
    ) {}
}
