<?php

namespace App\Actions\Lessons;

use App\Enums\DisputeReason;
use App\Enums\LessonStatus;
use App\Enums\Role;
use App\Exceptions\DisputeException;
use App\Models\Dispute;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Database\QueryException;

/**
 * CP8 (R150): the account holder opens a dispute on their own lesson, once, within 48h of
 * `ends_at`, while it is `completed` or `completed_reported` — the same two "already released"
 * and "not yet released" shapes `LedgerService::settle()` (resolution time) has to reconcile,
 * distinguished there only by escrow balance, distinguished here only by lesson status.
 *
 * The window is a bare class constant, not a `Settings` key: R150 states it as a plain "within
 * 48 hours" with no admin-configurability language anywhere in the PRD or PLAN (unlike
 * `cancel_window_hours`, which is explicitly per-price-band and frozen on the lesson —
 * invariant #11). Nothing here needs to be frozen on the lesson because the check is against
 * `ends_at`, itself already frozen at booking time.
 *
 * `LessonStateMachine::transition()` locks the lesson row and asserts the `completed`/
 * `completed_reported` -> `disputed` edge before `$work` runs, so a lesson already `disputed` or
 * `settled` is refused by the state machine itself with `LessonTransitionException` — the same
 * lock also serialises two concurrent opens on the same `completed` lesson, since only the first
 * can land on `disputed` and the second then fails that same edge assert. The DB unique index on
 * `disputes.lesson_id` (`2026_10_05_100000_create_disputes_table.php`) is still translated into a
 * clean `DisputeException` below, as defense in depth, matching `SubmitReview`'s precedent.
 */
class OpenDispute
{
    public const int WINDOW_HOURS = 48;

    /**
     * @throws DisputeException
     */
    public function __invoke(User $actor, Lesson $lesson, DisputeReason $reason, string $description): Dispute
    {
        if (mb_strlen($description) === 0 || mb_strlen($description) > 2000) {
            throw new DisputeException('A dispute needs a description of up to 2000 characters.');
        }

        $dispute = null;

        LessonStateMachine::transition($lesson, LessonStatus::Disputed, function (Lesson $locked) use ($actor, $reason, $description, &$dispute): void {
            // The row lock is taken by `transition()` itself, so eligibility is decided on the
            // database's status and `ends_at`, not a possibly-stale in-memory model.
            $problem = self::problemFor($actor, $locked);

            if ($problem !== null) {
                throw new DisputeException($problem);
            }

            try {
                $dispute = Dispute::query()->create([
                    'lesson_id' => $locked->id,
                    'opened_by_user_id' => $actor->id,
                    'reason' => $reason,
                    'description' => $description,
                ]);
                // `status` is deliberately not fillable (it is set once, by the DB default, never
                // chosen by the caller) — refresh so the returned model reflects it instead of null.
                $dispute->refresh();
            } catch (QueryException $e) {
                if ((string) $e->getCode() === '23505') {
                    throw new DisputeException('A dispute has already been opened for this lesson.');
                }

                throw $e;
            }
        });

        return $dispute;
    }

    /**
     * Why `$actor` cannot dispute `$lesson` right now, or null when they can. The dispute page
     * reads this too; the action re-checks it on the locked row, so it is a convenience there,
     * never the authority. Status membership is also asserted by `LessonStateMachine::assert()`
     * inside `transition()`, before this ever runs — checked again here first so a caller reading
     * this helper gets the clearer message instead of a bare `LessonTransitionException`.
     */
    public static function problemFor(User $actor, Lesson $lesson): ?string
    {
        if ($actor->role !== Role::AccountOwner || $lesson->learner->account_user_id !== $actor->id) {
            return 'Only the family that booked this lesson can dispute it.';
        }

        if ($lesson->dispute()->exists()) {
            return 'A dispute has already been opened for this lesson.';
        }

        if (! in_array($lesson->status, [LessonStatus::Completed, LessonStatus::CompletedReported], true)) {
            return 'This lesson cannot be disputed.';
        }

        if (now()->greaterThan($lesson->ends_at->addHours(self::WINDOW_HOURS))) {
            return 'The 48-hour window to dispute this lesson has passed.';
        }

        return null;
    }
}
