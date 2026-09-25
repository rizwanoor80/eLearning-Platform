<?php

namespace App\Actions\RecurringSlots;

use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Exceptions\LessonTransitionException;
use App\Models\Lesson;
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
}
