<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Actions\RecurringSlots\PauseRecurringSlot;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\RecurringSlotPauseReason;
use App\Enums\RecurringSlotStatus;
use App\Enums\TutorProfileStatus;
use App\Exceptions\LessonTransitionException;
use App\Exceptions\RecurringSlotException;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Lessons\LessonSettlement;
use App\Services\Lessons\LessonStateMachine;
use Throwable;

/**
 * R138: the sweep behind a tutor suspension (Safeguarding queue or the automatic 3-strikes path —
 * both go through `SuspendTutor`). Fired from `SuspendTutor` via `DB::afterCommit`, never inside
 * its own transaction (second 8d advisor consult, CYCLE-LOG 2026-09-27 18:12): the profile flip
 * must not roll back because one lesson's cascade step fails. Idempotent — re-checks the profile
 * is still `Suspended` under lock before doing anything, and every lesson gets its own
 * `LessonStateMachine::transition()`, so a single failure is caught, reported and listed, never
 * aborting the sweep or threatening the (already-committed) suspension.
 *
 * `reserved` lessons are released free (no ledger call — never charged); `confirmed` lessons are
 * refunded in full via `LessonSettlement::refundParent()` (the `MarkProviderFailure` shape, no
 * gateway call — CP5's). Both use `cancel_reason = tutor_suspended`, which — like
 * `tutor_unavailable` — keeps the lesson's `(recurring_slot_id, starts_at)` key: reinstating
 * restores nothing cancelled (R138), so that week is never regenerated. Every active weekly slot
 * of the tutor's is paused the same way, `paused_reason = tutor_suspended`.
 *
 * A `reserved` lesson with a payment attempt already in flight (`ChargeReservedLesson.php:119`'s
 * "stranded" guard) is left alone — the gateway may already have taken the money, and cancelling
 * here would strand it outside the ledger. It is reported back for an admin to resolve by hand.
 *
 * The initial status recheck runs `lockForUpdate()` but not inside its own `DB::transaction()`, so
 * on Postgres the row lock is released the moment that single (autocommit) `SELECT` returns — it
 * guards only against racing the exact instant `SuspendTutor`'s transaction committed, not across
 * the sweep that follows. That is enough here: nothing else can move the profile back out of
 * `Suspended` mid-sweep (only `ReinstateTutor` does, and it has no reason to run concurrently with
 * the suspension that just triggered this), so no wider lock is needed.
 *
 * The summary this returns is also written as a `tutor.suspension_sweep` audit row (`after`) so the
 * Safeguarding queue can show it — the caller (`SuspendTutor`, via `DB::afterCommit`) discards the
 * return value itself.
 */
class CancelSuspendedTutorLessons
{
    public function __construct(
        private readonly LessonSettlement $settlement,
        private readonly PauseRecurringSlot $pauseSlot,
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    /**
     * @return array{reserved_cancelled: int, confirmed_cancelled: int, slots_paused: int, skipped_lesson_ids: array<int, int>}
     */
    public function __invoke(TutorProfile $profile, ?User $actor = null): array
    {
        $locked = TutorProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== TutorProfileStatus::Suspended) {
            // Reinstated (or never actually suspended) between the commit and this running: nothing to do.
            return ['reserved_cancelled' => 0, 'confirmed_cancelled' => 0, 'slots_paused' => 0, 'skipped_lesson_ids' => []];
        }

        $skipped = [];
        $reservedCancelled = $this->cancelReservedLessons($locked, $actor, $skipped);
        $confirmedCancelled = $this->cancelConfirmedLessons($locked, $actor, $skipped);
        $slotsPaused = $this->pauseActiveSlots($locked, $actor);

        $summary = [
            'reserved_cancelled' => $reservedCancelled,
            'confirmed_cancelled' => $confirmedCancelled,
            'slots_paused' => $slotsPaused,
            'skipped_lesson_ids' => $skipped,
        ];

        ($this->recordAuditLog)($actor, 'tutor.suspension_sweep', $locked, null, $summary);

        return $summary;
    }

    /**
     * @param  array<int, int>  $skipped
     */
    private function cancelReservedLessons(TutorProfile $profile, ?User $actor, array &$skipped): int
    {
        $ids = Lesson::query()
            ->where('tutor_profile_id', $profile->id)
            ->where('status', LessonStatus::Reserved)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->pluck('id');

        $cancelled = 0;

        foreach ($ids as $id) {
            if ($this->hasPendingAttempt($id)) {
                $skipped[] = $id;

                continue;
            }

            try {
                $lesson = Lesson::query()->whereKey($id)->firstOrFail();

                LessonStateMachine::transition($lesson, LessonStatus::CancelledByTutor, function (Lesson $locked) use ($actor): void {
                    if ($locked->status !== LessonStatus::Reserved) {
                        throw new LessonTransitionException("Lesson {$locked->id} is no longer reserved.");
                    }

                    $locked->forceFill([
                        'next_charge_at' => null,
                        'cancelled_at' => now(),
                        'cancelled_by_user_id' => $actor?->id,
                        'cancel_reason' => LessonCancelReason::TutorSuspended->value,
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
     * @param  array<int, int>  $skipped
     */
    private function cancelConfirmedLessons(TutorProfile $profile, ?User $actor, array &$skipped): int
    {
        $ids = Lesson::query()
            ->where('tutor_profile_id', $profile->id)
            ->where('status', LessonStatus::Confirmed)
            ->orderBy('starts_at')
            ->pluck('id');

        $cancelled = 0;

        foreach ($ids as $id) {
            try {
                $lesson = Lesson::query()->whereKey($id)->firstOrFail();

                LessonStateMachine::transition($lesson, LessonStatus::CancelledByTutor, function (Lesson $locked) use ($actor): void {
                    if ($locked->status !== LessonStatus::Confirmed) {
                        throw new LessonTransitionException("Lesson {$locked->id} is no longer confirmed.");
                    }

                    $this->settlement->refundParent($locked, $actor);

                    $locked->forceFill([
                        'cancelled_at' => now(),
                        'cancelled_by_user_id' => $actor?->id,
                        'cancel_reason' => LessonCancelReason::TutorSuspended->value,
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

    private function pauseActiveSlots(TutorProfile $profile, ?User $actor): int
    {
        $ids = RecurringSlot::query()
            ->where('tutor_profile_id', $profile->id)
            ->where('status', RecurringSlotStatus::Active)
            ->pluck('id');

        $paused = 0;

        foreach ($ids as $id) {
            try {
                $slot = RecurringSlot::query()->whereKey($id)->firstOrFail();

                ($this->pauseSlot)(
                    $actor,
                    $slot,
                    RecurringSlotPauseReason::TutorSuspended,
                    null,
                    LessonStatus::CancelledByTutor,
                    LessonCancelReason::TutorSuspended,
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
