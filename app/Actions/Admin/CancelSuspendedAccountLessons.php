<?php

namespace App\Actions\Admin;

use App\Actions\RecordAuditLog;
use App\Actions\RecurringSlots\PauseRecurringSlot;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\RecurringSlotPauseReason;
use App\Enums\RecurringSlotStatus;
use App\Enums\UserStatus;
use App\Exceptions\LessonTransitionException;
use App\Exceptions\RecurringSlotException;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\RecurringSlot;
use App\Models\User;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Support\Collection;
use Throwable;

/**
 * R138 (Safeguarding queue): the parent-side sweep behind an account suspension
 * (`SuspendAccount`). Fired via `DB::afterCommit`, same reasoning as `CancelSuspendedTutorLessons`
 * (CYCLE-LOG 2026-09-27 18:12) — the account flip must not roll back because one lesson's cascade
 * step fails. Idempotent — re-checks the account is still `Suspended` under lock first.
 *
 * Scoped to the lessons reachable through this account's own learners (`learners.account_user_id`
 * — invariant #7, a minor never has its own login). The tutor side of a suspended account is
 * `SuspendAccount`'s own concern (it calls `SuspendTutor`, which runs `CancelSuspendedTutorLessons`
 * separately) — this action never touches `tutor_profile_id`.
 *
 * `reserved` lessons are released free as `cancelled_by_parent`, `cancel_reason = account_suspended`
 * (skipping one with a payment attempt already in flight, same "stranded" guard as
 * `ChargeReservedLesson.php:119`). `confirmed` lessons are left untouched — R138: an admin
 * decision, not automated — and listed back so the Safeguarding queue can show them. Every active
 * weekly slot reachable through this account's learners is paused, `paused_reason =
 * account_suspended`; the parent resumes it once the suspension is lifted (R138,
 * `RecurringSlotPolicy::resume`).
 *
 * The initial status recheck runs `lockForUpdate()` but not inside its own `DB::transaction()`, so
 * on Postgres the row lock is released the moment that single (autocommit) `SELECT` returns — it
 * guards only against racing the exact instant `SuspendAccount`'s transaction committed, not across
 * the sweep that follows. That is enough here: nothing else moves the account back out of
 * `Suspended` mid-sweep.
 *
 * The summary this returns is also written as a `user.suspension_sweep` audit row (`after`) so the
 * Safeguarding queue can show it — the caller (`SuspendAccount`, via `DB::afterCommit`) discards the
 * return value itself.
 */
class CancelSuspendedAccountLessons
{
    public function __construct(
        private readonly PauseRecurringSlot $pauseSlot,
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    /**
     * @return array{reserved_cancelled: int, slots_paused: int, skipped_lesson_ids: array<int, int>, confirmed_lesson_ids: array<int, int>}
     */
    public function __invoke(User $account, ?User $actor = null): array
    {
        $locked = User::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== UserStatus::Suspended) {
            // Reinstated (or never actually suspended) between the commit and this running: nothing to do.
            return ['reserved_cancelled' => 0, 'slots_paused' => 0, 'skipped_lesson_ids' => [], 'confirmed_lesson_ids' => []];
        }

        $learnerIds = $locked->learners()->pluck('id');

        $skipped = [];
        $reservedCancelled = $this->cancelReservedLessons($learnerIds, $actor, $skipped);
        $confirmedIds = $this->confirmedLessonIds($learnerIds);
        $slotsPaused = $this->pauseActiveSlots($learnerIds, $actor);

        $summary = [
            'reserved_cancelled' => $reservedCancelled,
            'slots_paused' => $slotsPaused,
            'skipped_lesson_ids' => $skipped,
            'confirmed_lesson_ids' => $confirmedIds,
        ];

        ($this->recordAuditLog)($actor, 'user.suspension_sweep', $locked, null, $summary);

        return $summary;
    }

    /**
     * @param  Collection<int, int>  $learnerIds
     * @param  array<int, int>  $skipped
     */
    private function cancelReservedLessons($learnerIds, ?User $actor, array &$skipped): int
    {
        $ids = Lesson::query()
            ->whereIn('learner_id', $learnerIds)
            ->where('status', LessonStatus::Reserved)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->pluck('id');

        $cancelled = 0;

        foreach ($ids as $id) {
            // pluck('id') is typed as a generic (mixed) Collection by Eloquent's stubs even
            // though the column is always an integer id — narrow it once here so $skipped stays
            // array<int, int>, matching its own docblock, rather than array<int, mixed>.
            $id = (int) $id;

            if ($this->hasPendingAttempt($id)) {
                $skipped[] = $id;

                continue;
            }

            try {
                $lesson = Lesson::query()->whereKey($id)->firstOrFail();

                LessonStateMachine::transition($lesson, LessonStatus::CancelledByParent, function (Lesson $locked) use ($actor): void {
                    if ($locked->status !== LessonStatus::Reserved) {
                        throw new LessonTransitionException("Lesson {$locked->id} is no longer reserved.");
                    }

                    $locked->forceFill([
                        'next_charge_at' => null,
                        'cancelled_at' => now(),
                        'cancelled_by_user_id' => $actor?->id,
                        'cancel_reason' => LessonCancelReason::AccountSuspended->value,
                    ]);
                });

                $cancelled++;
            } catch (Throwable $e) {
                report($e);
                $skipped[] = $id;
            }
        }

        return $cancelled;
    }

    /**
     * @param  Collection<int, int>  $learnerIds
     * @return array<int, int>
     */
    private function confirmedLessonIds($learnerIds): array
    {
        return Lesson::query()
            ->whereIn('learner_id', $learnerIds)
            ->where('status', LessonStatus::Confirmed)
            ->orderBy('starts_at')
            ->pluck('id')
            ->all();
    }

    /**
     * @param  Collection<int, int>  $learnerIds
     */
    private function pauseActiveSlots($learnerIds, ?User $actor): int
    {
        $ids = RecurringSlot::query()
            ->whereIn('learner_id', $learnerIds)
            ->where('status', RecurringSlotStatus::Active)
            ->pluck('id');

        $paused = 0;

        foreach ($ids as $id) {
            try {
                $slot = RecurringSlot::query()->whereKey($id)->firstOrFail();

                ($this->pauseSlot)(
                    $actor,
                    $slot,
                    RecurringSlotPauseReason::AccountSuspended,
                    null,
                    LessonStatus::CancelledByParent,
                    LessonCancelReason::AccountSuspended,
                );

                $paused++;
            } catch (RecurringSlotException $e) {
                // Already paused/ended by someone else since the query: nothing left to pause.
                report($e);
            }
        }

        return $paused;
    }

    private function hasPendingAttempt(int $lessonId): bool
    {
        return Payment::query()->where('lesson_id', $lessonId)->where('status', PaymentStatus::Pending)->exists();
    }
}
