<?php

namespace App\Enums;

enum LessonStatus: string
{
    case PendingPayment = 'pending_payment';
    case Reserved = 'reserved';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case CompletedReported = 'completed_reported';
    case Disputed = 'disputed';
    case Settled = 'settled';
    case Expired = 'expired';
    case CancelledByParent = 'cancelled_by_parent';
    case CancelledByTutor = 'cancelled_by_tutor';
    case CancelledPaymentFailed = 'cancelled_payment_failed';
    case NoShowStudent = 'no_show_student';
    case NoShowTutor = 'no_show_tutor';
    case NoShowBoth = 'no_show_both';
    case ProviderFailure = 'provider_failure';
    case Refunded = 'refunded';

    /**
     * Statuses that release the tutor's time slot (DATA_MODEL "Overlap
     * protection"): every other status keeps the slot taken.
     *
     * @return list<self>
     */
    public static function freeingSlot(): array
    {
        return [
            self::Expired,
            self::CancelledByParent,
            self::CancelledByTutor,
            self::CancelledPaymentFailed,
            self::Refunded,
        ];
    }

    /**
     * @return list<string>
     */
    public static function freeingSlotValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::freeingSlot());
    }

    public function blocksSlot(): bool
    {
        return ! in_array($this, self::freeingSlot(), true);
    }

    /**
     * Terminal per docs/DATA_MODEL.md:250 ("Terminal states: `expired,
     * refunded, settled, completed_reported, cancelled_payment_failed,
     * provider_failure`") plus `cancelled_by_tutor` (a `cancelled_*` from
     * `reserved` is terminal with no money movement, same line). This is a
     * money/obligation reading, not "no outgoing edges" in
     * `LessonStateMachine::edges()` — `completed_reported` still has the
     * `disputed` safety-valve edge, so a graph-terminal check would wrongly
     * treat every completed lesson as non-terminal forever. Used by the
     * learner/user deletion guards (R54): a person is only removable once
     * none of their lessons still have money or an obligation open.
     *
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [
            self::Expired,
            self::Refunded,
            self::Settled,
            self::CompletedReported,
            self::CancelledPaymentFailed,
            self::ProviderFailure,
            self::CancelledByTutor,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    /**
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::terminal());
    }
}
