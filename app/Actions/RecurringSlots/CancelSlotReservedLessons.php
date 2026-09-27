<?php

namespace App\Actions\RecurringSlots;

use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\LessonTransitionException;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\RecurringSlot;
use App\Models\User;
use App\Services\Lessons\LessonStateMachine;
use Carbon\CarbonInterface;

/**
 * Cancels a slot's future `reserved` lessons free (R99): no ledger call, no payment update, no
 * strike, no follow-on transition — a `reserved` lesson has never been charged. Each lesson
 * moves through `LessonStateMachine::transition()` on its own locked row, and `Reserved` is
 * re-checked there: `confirmed → cancelled_by_*` is also a declared edge, so a lesson that was
 * charged between the query and the lock must be left alone, not refunded by accident.
 *
 * A lesson with a `pending` payment attempt is left alone too (R138, same stranded-payment guard
 * as `ChargeReservedLesson::begin()` and the two suspension sweeps): the gateway may already have
 * taken that money, and cancelling the lesson here would abandon it with no route back to
 * `succeed()`/`fail()` and so no route to the ledger. This matters for every caller of this action,
 * not only the suspension sweeps that already skip it themselves before ever reaching this call —
 * an admin or 4c payment-failure pause reaches this directly with no such check of its own.
 *
 * Called from inside the slot action's transaction, with the slot row already locked.
 */
class CancelSlotReservedLessons
{
    /**
     * @param  CarbonInterface|null  $from  cancel only lessons starting at or after this instant (default: now, exclusive of the past)
     * @return int the number of lessons cancelled
     */
    public function __invoke(
        RecurringSlot $slot,
        LessonStatus $to,
        ?User $actor,
        LessonCancelReason $reason,
        ?CarbonInterface $from = null,
    ): int {
        $from ??= now();

        $ids = Lesson::query()
            ->where('recurring_slot_id', $slot->id)
            ->where('status', LessonStatus::Reserved)
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->pluck('id');

        $cancelled = 0;

        foreach ($ids as $id) {
            if ($this->hasPendingAttempt($id)) {
                continue;
            }

            try {
                $lesson = Lesson::query()->whereKey($id)->firstOrFail();

                LessonStateMachine::transition($lesson, $to, function (Lesson $locked) use ($actor, $reason): void {
                    if ($locked->status !== LessonStatus::Reserved) {
                        throw new LessonTransitionException("Lesson {$locked->id} is no longer reserved.");
                    }

                    $locked->forceFill([
                        'cancelled_at' => now(),
                        'cancelled_by_user_id' => $actor?->id,
                        'cancel_reason' => $reason->value,
                    ]);
                });

                $cancelled++;
            } catch (LessonTransitionException) {
                // Moved on (confirmed, expired, cancelled) since the query: not ours to cancel.
                continue;
            }
        }

        return $cancelled;
    }

    private function hasPendingAttempt(int $lessonId): bool
    {
        return Payment::query()->where('lesson_id', $lessonId)->where('status', PaymentStatus::Pending)->exists();
    }
}
