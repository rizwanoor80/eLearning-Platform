<?php

namespace App\Services\Lessons;

use App\Enums\PaymentStatus;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Ledger\LedgerService;

/**
 * The two money outcomes the attendance paths share (CP6): the parent is refunded, or the tutor is
 * paid. Both run inside a `LessonStateMachine::transition()` closure, on the locked lesson, so the
 * ledger entries, the payment row and the status commit together. The ledger writes go only through
 * `LedgerService` (invariant 1); which outcome applies is the caller's decision (invariant 13).
 */
class LessonSettlement
{
    public function __construct(private LedgerService $ledger) {}

    public function refundParent(Lesson $locked, ?User $by): void
    {
        $this->ledger->refund($locked, $by);

        $locked->payment?->update([
            'status' => PaymentStatus::Refunded,
            'refunded_amount' => $locked->price,
        ]);
    }

    public function payTutor(Lesson $locked, ?User $by): void
    {
        $this->ledger->release($locked, $by);

        $locked->forceFill(['escrow_released_at' => now()]);
    }
}
