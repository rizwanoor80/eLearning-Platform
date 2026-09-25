<?php

namespace App\Enums;

/**
 * What one run of `ChargeReservedLesson` did with one weekly lesson (R101).
 */
enum ChargeOutcome: string
{
    /** Charged: `reserved -> confirmed` with a HOLD. */
    case Charged = 'charged';

    /** The attempt failed and a retry is scheduled. */
    case RetryScheduled = 'retry_scheduled';

    /** The last retry failed: `reserved -> cancelled_payment_failed`. */
    case Cancelled = 'cancelled';

    /** The lesson's start passed while it was uncharged: cancelled without counting against the slot. */
    case Missed = 'missed';

    /** Nothing to do (not due, already handled, slot not active, or no gateway is configured). */
    case Skipped = 'skipped';

    /** Money was taken but the lesson could not be confirmed: needs manual review. */
    case NeedsReview = 'needs_review';
}
