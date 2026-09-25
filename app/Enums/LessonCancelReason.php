<?php

namespace App\Enums;

/**
 * Machine-readable values written to `lessons.cancel_reason` (a free-text column)
 * when the platform, not a person's typed note, cancels a lesson. Only the values
 * something reads back are named here.
 */
enum LessonCancelReason: string
{
    /**
     * A weekly slot was paused (R99): the future `reserved` lessons are cancelled free
     * as `cancelled_by_parent`. The recurring-key index on `lessons` frees these rows'
     * `(recurring_slot_id, starts_at)` so that resume can regenerate the week.
     */
    case SlotPaused = 'slot_paused';

    /**
     * A weekly slot was ended (R99) by the parent, the tutor or an admin: the affected
     * `reserved` lessons are cancelled free. Unlike a pause, these rows keep their
     * `(recurring_slot_id, starts_at)` key — an ended slot is never regenerated.
     */
    case SlotEnded = 'slot_ended';

    /**
     * A weekly lesson's start time passed while it was still `reserved` and uncharged (the charge job
     * was not running, or the lesson was generated too late to charge): cancelled as
     * `cancelled_payment_failed`, but it is the platform's timing, not the parent's card, so it does
     * not count toward the slot's failure counter. The email wording reads this value.
     */
    case ChargeWindowMissed = 'charge_window_missed';

    /**
     * A weekly lesson fell due for charging while its tutor was no longer `bookable()` (suspended,
     * permit lapsed, account deleted) — R104: cancelled as `cancelled_by_tutor` with no actor, never
     * charged, no strike, and the slot stays active. It keeps its `(recurring_slot_id, starts_at)`
     * key, so a reinstated tutor's next generation run does not recreate that week. Being a machine
     * value also keeps the generic free-skip email quiet.
     */
    case TutorUnavailable = 'tutor_unavailable';

    /**
     * Whether typed text collides with a machine value. `lessons_recurring_slot_starts_at_unique`
     * frees a key by reading `cancel_reason`, so a person's own note must never be able to equal
     * one; any future typed-reason input must refuse it.
     */
    public static function isReserved(?string $text): bool
    {
        return $text !== null && self::tryFrom(trim($text)) !== null;
    }
}
