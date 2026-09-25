<?php

namespace App\Actions\RecurringSlots;

use App\Actions\RecordAuditLog;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\RecurringSlotPauseReason;
use App\Enums\RecurringSlotStatus;
use App\Exceptions\RecurringSlotException;
use App\Models\RecurringSlot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Pauses an `active` weekly slot (R99): generation stops and every future `reserved` lesson is
 * cancelled free as `cancelled_by_parent` with `cancel_reason = slot_paused`. The state machine
 * has no admin-cancel state and is frozen, so the actor (null for the system) and the reason
 * carry the truth. A paused slot keeps its weekday/time. An admin pauses (`Admin`); 4e's charge
 * job pauses with `PaymentFailed` and no actor.
 */
class PauseRecurringSlot
{
    public function __construct(private readonly CancelSlotReservedLessons $cancelLessons, private readonly RecordAuditLog $audit) {}

    /**
     * @throws RecurringSlotException
     */
    public function __invoke(?User $actor, RecurringSlot $slot, RecurringSlotPauseReason $reason = RecurringSlotPauseReason::Admin, ?string $note = null): RecurringSlot
    {
        if ($reason === RecurringSlotPauseReason::Admin) {
            if ($actor === null || ! Gate::forUser($actor)->allows('pause', $slot)) {
                throw new RecurringSlotException('Only an admin can pause a weekly slot.');
            }
        } elseif ($actor !== null) {
            throw new RecurringSlotException('A payment-failure pause is made by the system, not by a person.');
        }

        return DB::transaction(function () use ($actor, $slot, $reason, $note): RecurringSlot {
            $locked = RecurringSlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RecurringSlotStatus::Active) {
                throw new RecurringSlotException('Only an active weekly slot can be paused.');
            }

            $locked->forceFill(['status' => RecurringSlotStatus::Paused, 'paused_reason' => $reason])->save();

            $cancelled = ($this->cancelLessons)($locked, LessonStatus::CancelledByParent, $actor, LessonCancelReason::SlotPaused);

            ($this->audit)($actor, 'recurring_slot.paused', $locked,
                ['status' => RecurringSlotStatus::Active->value],
                ['status' => RecurringSlotStatus::Paused->value, 'paused_reason' => $reason->value, 'cancelled_lessons' => $cancelled, 'note' => $note],
            );

            return $locked;
        });
    }
}
