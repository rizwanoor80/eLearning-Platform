<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The slot actions (`CreateRecurringSlot`, `EndRecurringSlot`, `PauseRecurringSlot`,
 * `ResumeRecurringSlot`) throw this for every rejectable input: the caller may not act on the
 * slot, the tutor/price/time/card is not acceptable, the slot is not in a state the action
 * applies to, or the database's `recurring_slots_live_unique` refused a racing second slot.
 */
class RecurringSlotException extends RuntimeException {}
