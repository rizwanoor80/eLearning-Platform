<?php

namespace App\Actions\RecurringSlots;

use App\Actions\RecordAuditLog;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\RecurringSlotPauseReason;
use App\Enums\RecurringSlotStatus;
use App\Events\RecurringSlots\RecurringSlotPaused;
use App\Exceptions\RecurringSlotException;
use App\Models\RecurringSlot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Pauses an `active` weekly slot (R99): generation stops and every future `reserved` lesson is
 * cancelled free. The state machine has no admin-cancel state and is frozen, so the actor (null
 * for the system) and the reason carry the truth. A paused slot keeps its weekday/time. An admin
 * pauses (`Admin`); 4e's charge job pauses with `PaymentFailed` and no actor; R138's suspension
 * cascade pauses with `TutorSuspended`/`AccountSuspended`, actor the admin who suspended (or null
 * for the automatic 3-strikes path).
 *
 * `$cancelTo`/`$cancelReason` default to `cancelled_by_parent`/`slot_paused` (R99), which frees
 * the lesson's `(recurring_slot_id, starts_at)` key so resume can regenerate the week. R138's
 * caller passes its own status/reason (`cancelled_by_tutor`/`tutor_suspended` or
 * `cancelled_by_parent`/`account_suspended`) so those rows keep their key instead — reinstating
 * restores nothing cancelled (R138). In practice R138's caller cancels a tutor's/account's
 * `reserved` lessons directly before pausing their slots, so this second pass over the slot
 * usually finds nothing left to cancel; it stays here so the reason is still correct if one
 * slipped through generation in between.
 */
class PauseRecurringSlot
{
    public function __construct(private readonly CancelSlotReservedLessons $cancelLessons, private readonly RecordAuditLog $audit) {}

    /**
     * @throws RecurringSlotException
     */
    public function __invoke(
        ?User $actor,
        RecurringSlot $slot,
        RecurringSlotPauseReason $reason = RecurringSlotPauseReason::Admin,
        ?string $note = null,
        LessonStatus $cancelTo = LessonStatus::CancelledByParent,
        LessonCancelReason $cancelReason = LessonCancelReason::SlotPaused,
    ): RecurringSlot {
        if ($reason === RecurringSlotPauseReason::Admin) {
            if ($actor === null || ! Gate::forUser($actor)->allows('pause', $slot)) {
                throw new RecurringSlotException('Only an admin can pause a weekly slot.');
            }
        } elseif (in_array($reason, [RecurringSlotPauseReason::TutorSuspended, RecurringSlotPauseReason::AccountSuspended], true)) {
            if ($actor !== null && ! Gate::forUser($actor)->allows('pause', $slot)) {
                throw new RecurringSlotException('A safeguarding-suspension pause is made by the system or an admin.');
            }
        } elseif ($actor !== null) {
            throw new RecurringSlotException('A payment-failure pause is made by the system, not by a person.');
        }

        return DB::transaction(function () use ($actor, $slot, $reason, $note, $cancelTo, $cancelReason): RecurringSlot {
            $locked = RecurringSlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RecurringSlotStatus::Active) {
                throw new RecurringSlotException('Only an active weekly slot can be paused.');
            }

            $locked->forceFill(['status' => RecurringSlotStatus::Paused, 'paused_reason' => $reason])->save();

            $cancelled = ($this->cancelLessons)($locked, $cancelTo, $actor, $cancelReason);

            ($this->audit)($actor, 'recurring_slot.paused', $locked,
                ['status' => RecurringSlotStatus::Active->value],
                ['status' => RecurringSlotStatus::Paused->value, 'paused_reason' => $reason->value, 'cancelled_lessons' => $cancelled, 'note' => $note],
            );

            DB::afterCommit(fn () => RecurringSlotPaused::dispatch($locked, $cancelled));

            return $locked;
        });
    }
}
