<?php

namespace App\Actions\RecurringSlots;

use App\Actions\RecordAuditLog;
use App\Enums\RecurringSlotStatus;
use App\Exceptions\RecurringSlotException;
use App\Models\RecurringSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Resumes a `paused` weekly slot (admin). The slot never lost its weekday/time (a paused slot
 * still holds `recurring_slots_live_unique`), so resuming cannot collide with another slot.
 * `generated_until` is reset to today in the slot's timezone (never below the day before
 * `starts_on`) so the daily generator (4c) revisits the dates the pause cancelled; those rows
 * freed their `(recurring_slot_id, starts_at)` key, so the refill inserts cleanly. The failure
 * counter starts again from zero.
 */
class ResumeRecurringSlot
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * @throws RecurringSlotException
     */
    public function __invoke(User $actor, RecurringSlot $slot, ?string $note = null): RecurringSlot
    {
        if (! Gate::forUser($actor)->allows('resume', $slot)) {
            throw new RecurringSlotException('Only an admin can resume a weekly slot.');
        }

        return DB::transaction(function () use ($actor, $slot, $note): RecurringSlot {
            $locked = RecurringSlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RecurringSlotStatus::Paused) {
                throw new RecurringSlotException('Only a paused weekly slot can be resumed.');
            }

            // The slot's own calendar, not UTC: `generated_until` is a date in the slot's timezone, and a
            // slot that has not started yet must not be dragged below the day before `starts_on`.
            $today = CarbonImmutable::now($locked->timezone)->startOfDay();
            $resumeFrom = $today->max($locked->starts_on->toImmutable()->subDay()->startOfDay());
            $end = $locked->effectiveEndDate();

            if ($end !== null && $end->toDateString() < $today->toDateString()) {
                throw new RecurringSlotException('This weekly slot has passed its end date, so it cannot be resumed.');
            }

            $before = ['status' => RecurringSlotStatus::Paused->value, 'paused_reason' => $locked->paused_reason?->value];

            $locked->forceFill([
                'status' => RecurringSlotStatus::Active,
                'paused_reason' => null,
                'consecutive_charge_failures' => 0,
                'generated_until' => $resumeFrom->toDateString(),
            ])->save();

            ($this->audit)($actor, 'recurring_slot.resumed', $locked, $before,
                ['status' => RecurringSlotStatus::Active->value, 'generated_until' => $resumeFrom->toDateString(), 'note' => $note],
            );

            return $locked;
        });
    }
}
