<?php

namespace App\Actions\RecurringSlots;

use App\Actions\RecordAuditLog;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\RecurringSlotStatus;
use App\Enums\Role;
use App\Events\RecurringSlots\RecurringSlotEnded;
use App\Exceptions\RecurringSlotException;
use App\Models\RecurringSlot;
use App\Models\User;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Ends a weekly slot (R99). The parent or an admin ends it at once: status `ended`, every future
 * `reserved` lesson cancelled free as `cancelled_by_parent` (`slot_ended`); `confirmed` lessons
 * were charged and stay. A tutor gives notice instead (`recurring_tutor_end_notice_days`): the
 * slot stays `active`/`paused` through `end_effective_on`, lessons after that day are cancelled
 * as `cancelled_by_tutor` — no strike, this is the tutor ending the arrangement, not skipping a
 * lesson — and 4c's daily run flips the slot to `ended` once the date has passed. Lessons inside
 * the notice period are left to run.
 */
class EndRecurringSlot
{
    public function __construct(private readonly CancelSlotReservedLessons $cancelLessons, private readonly RecordAuditLog $audit) {}

    /**
     * @throws RecurringSlotException
     */
    public function __invoke(User $actor, RecurringSlot $slot, ?string $note = null): RecurringSlot
    {
        if (! Gate::forUser($actor)->allows('end', $slot)) {
            throw new RecurringSlotException('You cannot end this weekly slot.');
        }

        return DB::transaction(function () use ($actor, $slot, $note): RecurringSlot {
            $locked = RecurringSlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === RecurringSlotStatus::Ended) {
                throw new RecurringSlotException('This weekly slot has already ended.');
            }

            return $actor->role === Role::Tutor
                ? $this->endWithNotice($actor, $locked, $note)
                : $this->endNow($actor, $locked, $note);
        });
    }

    private function endNow(User $actor, RecurringSlot $slot, ?string $note): RecurringSlot
    {
        $before = ['status' => $slot->status->value, 'end_effective_on' => $slot->end_effective_on?->toDateString()];

        $slot->forceFill([
            'status' => RecurringSlotStatus::Ended,
            'ended_by_user_id' => $actor->id,
            'ended_at' => now(),
            // An immediate end supersedes any tutor notice: `end_effective_on` set means "a tutor gave notice", nothing else.
            'end_effective_on' => null,
        ])->save();

        $cancelled = ($this->cancelLessons)($slot, LessonStatus::CancelledByParent, $actor, LessonCancelReason::SlotEnded);

        ($this->audit)($actor, 'recurring_slot.ended', $slot, $before,
            ['status' => RecurringSlotStatus::Ended->value, 'cancelled_lessons' => $cancelled, 'note' => $note],
        );

        DB::afterCommit(fn () => RecurringSlotEnded::dispatch($slot, $cancelled, $actor->role));

        return $slot;
    }

    private function endWithNotice(User $actor, RecurringSlot $slot, ?string $note): RecurringSlot
    {
        if ($slot->end_effective_on !== null) {
            throw new RecurringSlotException('Notice has already been given to end this weekly slot.');
        }

        $noticeDays = (int) Settings::get('recurring_tutor_end_notice_days');
        $effectiveOn = CarbonImmutable::now($slot->timezone)->startOfDay()->addDays($noticeDays);
        // The last local date the slot still occurs; the first cancelled lesson is the day after.
        $cancelFrom = $effectiveOn->addDay()->startOfDay()->utc();

        $before = ['status' => $slot->status->value, 'end_effective_on' => null];

        $slot->forceFill([
            'end_effective_on' => $effectiveOn->toDateString(),
            'ended_by_user_id' => $actor->id,
            'ended_at' => now(),
        ])->save();

        $cancelled = ($this->cancelLessons)($slot, LessonStatus::CancelledByTutor, $actor, LessonCancelReason::SlotEnded, $cancelFrom);

        ($this->audit)($actor, 'recurring_slot.ended', $slot, $before,
            ['status' => $slot->status->value, 'end_effective_on' => $effectiveOn->toDateString(), 'notice_days' => $noticeDays, 'cancelled_lessons' => $cancelled, 'note' => $note],
        );

        DB::afterCommit(fn () => RecurringSlotEnded::dispatch($slot, $cancelled, $actor->role));

        return $slot;
    }
}
