<?php

namespace App\Actions\Lessons;

use App\Actions\RecordAuditLog;
use App\Enums\DisputeReason;
use App\Enums\LessonStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\DisputeException;
use App\Models\Dispute;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Database\QueryException;

/**
 * R243: the admin's override for a lesson `SettleSuspendedLesson` closed as `completed_reported` — the tutor was
 * paid because the suspended parent was the absent one, and a suspended parent cannot open a dispute herself. The
 * admin opens it for them; the existing Disputes resource and `ResolveDispute` settle it with the two dials,
 * already-released escrow included, unchanged.
 *
 * Only a lesson carrying the `lesson.settled_suspension` audit row, only from `completed_reported`, and only within
 * `WINDOW_DAYS` of `ends_at`. The `refunded` tutor-suspension row is deliberately out: the parent was refunded in
 * full, and tutors do not open disputes (PRD §2.10). The dispute row is created as `OpenDispute` creates it, with the
 * admin as `opened_by_user_id`; eligibility is decided on the locked row inside `LessonStateMachine::transition()`,
 * which also serialises two concurrent opens.
 *
 * Lock order (R263, 18d): the lesson row only, exactly as `OpenDispute`. It takes no `users` or `tutor_profiles` lock, so it
 * cannot wait on `GeneratePayoutBatch`, `AnonymizeUser` or `MarkPayoutLinePaid` in a circle.
 */
class OpenSuspensionDispute
{
    public const int WINDOW_DAYS = 7;

    public const AUDIT_OPENED = 'lesson.dispute_opened_by_admin';

    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @throws DisputeException
     */
    public function __invoke(User $admin, Lesson $lesson, DisputeReason $reason, string $description): Dispute
    {
        if (mb_strlen($description) === 0 || mb_strlen($description) > 2000) {
            throw new DisputeException('A dispute needs a description of up to 2000 characters.');
        }

        $dispute = null;

        LessonStateMachine::transition($lesson, LessonStatus::Disputed, function (Lesson $locked) use ($admin, $reason, $description, &$dispute): void {
            $problem = self::problemFor($admin, $locked);

            if ($problem !== null) {
                throw new DisputeException($problem);
            }

            try {
                $dispute = Dispute::query()->create([
                    'lesson_id' => $locked->id,
                    'opened_by_user_id' => $admin->id,
                    'reason' => $reason,
                    'description' => $description,
                ]);
                $dispute->refresh();
            } catch (QueryException $e) {
                if ((string) $e->getCode() === '23505') {
                    throw new DisputeException('A dispute has already been opened for this lesson.');
                }

                throw $e;
            }

            // The description is the admin's free text; the audit row carries ids and the reason only.
            ($this->recordAuditLog)($admin, self::AUDIT_OPENED, $locked, ['status' => LessonStatus::CompletedReported->value], [
                'status' => LessonStatus::Disputed->value,
                'dispute_id' => $dispute->id,
                'reason' => $reason->value,
            ]);
        });

        return $dispute;
    }

    /**
     * Why `$admin` cannot open a dispute on `$lesson` right now, or null when they can. The Filament action reads this
     * for its visibility; the action re-checks it on the locked row, so it is a convenience there, never the authority.
     */
    public static function problemFor(User $admin, Lesson $lesson): ?string
    {
        if ($admin->role !== Role::Admin || $admin->status !== UserStatus::Active) {
            return 'Only an active admin may open a dispute for a suspended account.';
        }

        if ($lesson->status !== LessonStatus::CompletedReported) {
            return 'This lesson cannot be disputed.';
        }

        if (now()->greaterThan($lesson->ends_at->copy()->addDays(self::WINDOW_DAYS))) {
            return 'The '.self::WINDOW_DAYS.'-day window to dispute this lesson has passed.';
        }

        // The two cheap checks above come first: the Filament action's `visible()` runs this for every table row.
        if (! SettleSuspendedLesson::wasSettled($lesson)) {
            return 'This lesson was not settled after a suspension.';
        }

        if ($lesson->dispute()->exists()) {
            return 'A dispute has already been opened for this lesson.';
        }

        return null;
    }
}
