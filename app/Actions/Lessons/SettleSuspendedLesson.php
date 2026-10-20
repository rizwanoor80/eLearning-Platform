<?php

namespace App\Actions\Lessons;

use App\Actions\RecordAuditLog;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Exceptions\LessonTransitionException;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Services\Lessons\LessonSettlement;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * R238: a lesson whose room was closed because an account was suspended (the `lesson.room_closed_suspension` audit
 * row, written by `CloseSuspendedAccountRooms`) settles with the suspended party as the absent one, through edges the
 * state machine already has. Called by `SettleEndedLesson` first; null means "not mine", and its own rules run as before.
 *
 *  - parent suspended, lesson `confirmed`: a late parent cancellation (PRD §4) — `confirmed -> cancelled_by_parent ->
 *    completed_reported`, tutor paid in full, exactly the money `CancelLesson`'s inside-window branch moves.
 *  - parent suspended, `in_progress`, not both joined: a student no-show — `in_progress -> no_show_student ->
 *    completed_reported`, tutor paid.
 *  - tutor suspended (alone or with the parent), `in_progress`, not both joined: `in_progress -> no_show_tutor ->
 *    refunded`, no tutor pay and no strike (the suspension already records the cause).
 *  - tutor suspended, `confirmed`: not mine — `CancelSuspendedTutorLessons` cancels and refunds it; if one slips
 *    through, the unattended-lesson refund in `SettleEndedLesson` stands.
 *  - both joined, no audit row, or an audit row for a user who is neither the tutor nor the lesson's account owner:
 *    not mine.
 *
 * Only at the end plus `video.close_grace_minutes`, like the unattended refund, so a suspension that lands during the
 * lesson has had its full window. Decided in one transaction: the join facts are re-read on the locked row (a late
 * join webhook can still stamp one before the first transition), and the settlement audit row is written last, so a
 * lost race rolls it back too. Refund and pay are chosen per row here, never inferred from each other (invariant 13);
 * the actor is null (the system), as in `SettleEndedLesson::unattended()`.
 */
class SettleSuspendedLesson
{
    public const AUDIT_ROOM_CLOSED = 'lesson.room_closed_suspension';

    public const AUDIT_SETTLED = 'lesson.settled_suspension';

    public function __construct(
        private LessonSettlement $settlement,
        private RecordAuditLog $recordAuditLog,
    ) {}

    public function __invoke(Lesson $lesson): ?LessonStatus
    {
        if (! in_array($lesson->status, [LessonStatus::InProgress, LessonStatus::Confirmed], true)) {
            return null;
        }

        if ($lesson->ends_at->copy()->addMinutes((int) config('video.close_grace_minutes'))->isFuture()) {
            return null;
        }

        // The common candidate has no suspension row: answer that before opening a transaction or loading a relation.
        if (! AuditLog::query()->where('action', self::AUDIT_ROOM_CLOSED)->where('subject_type', Lesson::class)->where('subject_id', $lesson->id)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($lesson): ?LessonStatus {
            $suspended = $this->suspendedUserIds($lesson);

            if ($suspended === []) {
                return null;
            }

            $tutorSuspended = in_array($lesson->tutorProfile->user_id, $suspended, true);
            $parentSuspended = in_array($lesson->learner->account_user_id, $suspended, true);

            if ($lesson->status === LessonStatus::Confirmed) {
                return $parentSuspended && ! $tutorSuspended ? $this->parentConfirmed($lesson, $suspended) : null;
            }

            if ($this->bothJoined($lesson)) {
                return null;
            }

            return $tutorSuspended
                ? $this->tutorInProgress($lesson, $suspended)
                : $this->parentInProgress($lesson, $suspended);
        });
    }

    /**
     * Whether this sweep settled the lesson: the audit row `settled()` writes, inside the transaction that holds the
     * transitions. The cancellation listeners read it (R242) because the `confirmed -> cancelled_by_parent` it writes
     * is announced to nobody after the lesson has ended, while `account_suspended` alone also marks the free release of
     * `reserved` lessons (`CancelSuspendedAccountLessons`), whose notifications stand.
     */
    public static function wasSettled(Lesson $lesson): bool
    {
        return AuditLog::query()
            ->where('action', self::AUDIT_SETTLED)
            ->where('subject_type', Lesson::class)
            ->where('subject_id', $lesson->id)
            ->exists();
    }

    /**
     * The tutor's user id and the account owner's, whichever of them has a closed-room audit row on this lesson.
     *
     * @return list<int>
     */
    private function suspendedUserIds(Lesson $lesson): array
    {
        $parties = [$lesson->tutorProfile->user_id, $lesson->learner->account_user_id];

        $ids = AuditLog::query()
            ->where('action', self::AUDIT_ROOM_CLOSED)
            ->where('subject_type', Lesson::class)
            ->where('subject_id', $lesson->id)
            ->get()
            ->map(fn (AuditLog $row): int => (int) ($row->after['account_user_id'] ?? 0))
            ->filter(fn (int $id): bool => in_array($id, $parties, true))
            ->unique()
            ->sort()
            ->all();

        return array_values($ids);
    }

    private function bothJoined(Lesson $lesson): bool
    {
        return $lesson->tutor_joined_at !== null && $lesson->learner_joined_at !== null;
    }

    /**
     * @param  list<int>  $suspended
     */
    private function parentConfirmed(Lesson $lesson, array $suspended): LessonStatus
    {
        $lesson = LessonStateMachine::transition($lesson, LessonStatus::CancelledByParent, function (Lesson $locked): void {
            $locked->forceFill([
                'cancelled_at' => now(),
                'cancelled_by_user_id' => null,
                'cancel_reason' => LessonCancelReason::AccountSuspended->value,
            ]);
        });

        LessonStateMachine::transition($lesson, LessonStatus::CompletedReported, function (Lesson $locked): void {
            $this->settlement->payTutor($locked, null);
        });

        return $this->settled($lesson, $suspended, 'parent_confirmed', LessonStatus::CompletedReported);
    }

    /**
     * @param  list<int>  $suspended
     */
    private function parentInProgress(Lesson $lesson, array $suspended): LessonStatus
    {
        $lesson = LessonStateMachine::transition($lesson, LessonStatus::NoShowStudent, fn (Lesson $locked) => $this->assertNotBothJoined($locked));

        LessonStateMachine::transition($lesson, LessonStatus::CompletedReported, function (Lesson $locked): void {
            $this->settlement->payTutor($locked, null);
        });

        return $this->settled($lesson, $suspended, 'parent_in_progress', LessonStatus::CompletedReported);
    }

    /**
     * @param  list<int>  $suspended
     */
    private function tutorInProgress(Lesson $lesson, array $suspended): LessonStatus
    {
        $lesson = LessonStateMachine::transition($lesson, LessonStatus::NoShowTutor, fn (Lesson $locked) => $this->assertNotBothJoined($locked));

        LessonStateMachine::transition($lesson, LessonStatus::Refunded, function (Lesson $locked): void {
            $this->settlement->refundParent($locked, null);
        });

        return $this->settled($lesson, $suspended, 'tutor_in_progress', LessonStatus::Refunded);
    }

    /**
     * A join that landed after the sweep read the lesson wins: the sweep's next run finds both sides in and completes it.
     *
     * @throws LessonTransitionException
     */
    private function assertNotBothJoined(Lesson $locked): void
    {
        if ($this->bothJoined($locked)) {
            throw new LessonTransitionException("Lesson {$locked->id} was joined by both sides; it is not a suspension no-show.");
        }
    }

    /**
     * @param  list<int>  $suspended
     */
    private function settled(Lesson $lesson, array $suspended, string $row, LessonStatus $to): LessonStatus
    {
        ($this->recordAuditLog)(null, self::AUDIT_SETTLED, $lesson, null, ['suspended_user_ids' => $suspended, 'row' => $row, 'status' => $to->value]);

        return $to;
    }
}
