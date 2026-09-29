<?php

namespace App\Actions\Lessons;

use App\Actions\RecordAuditLog;
use App\Enums\LessonStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\AttendanceException;
use App\Exceptions\LessonTransitionException;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Lessons\LessonStateMachine;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Admin only (PRD §7, CP8 task 2, R151): closes out a lesson stuck past its own scheduled end that
 * nothing else has ruled on — STATUS §6 0b's "a lesson can sit in `in_progress` forever if only one
 * party joined and nobody marks a no-show" (fits R129: manual, no automatic close). Moves the lesson
 * to `completed` through the state machine's own existing edges — `in_progress -> completed`
 * directly, or `confirmed -> in_progress -> completed` chained through both edges inside one
 * transaction when nobody ever joined at all — and no further: `in_progress -> provider_failure` is
 * not a declared edge and this action does not add one (R94, STATUS §6 0a). It does not call
 * settlement itself: money stays in escrow for the normal release path — the tutor's report
 * (`SubmitProgressReport`) or the 72h sweep (`AutoReleaseLesson`), unchanged, exactly as R151 states.
 *
 * `ends_at` must already be in the past for either starting status, mirroring
 * `SettleEndedLesson::complete()`'s own guard — nothing is force-completed mid-session.
 *
 * `report_due_at`/`auto_release_at` are anchored on `now()`, not `ends_at` (release-path DECISION,
 * CYCLE-LOG `08:13`): every consumer of those columns (`AutoReleaseLesson`, `ReviewLateReports`,
 * `SubmitProgressReport`, the tutor dashboard's "due by") trusts the stored value, never recomputing
 * `ends_at + hours` itself, so anchoring the deadline on the instant the admin actually closed the
 * lesson out — rather than on a scheduled end that may be days past — cannot make a delay the admin
 * (or a Daily webhook failure, STATUS §6 0c(a)) caused look like a genuinely late tutor report.
 *
 * The confirmed -> in_progress hop sets no join timestamp: a force-completed lesson may genuinely
 * have neither `tutor_joined_at` nor `learner_joined_at` set. `SyncConversationForLesson`'s
 * `first_lesson_completed_at` unmask on any `-> completed` transition therefore carries the same risk
 * here it always does for a stuck lesson — accepted as deliberate admin-judgment design (release-path
 * DECISION, CYCLE-LOG `08:13`): the required note is the safety mechanism, same trust model as
 * `MarkProviderFailure`.
 */
class ForceCompleteLesson
{
    /**
     * @throws AttendanceException
     * @throws LessonTransitionException
     */
    public function __invoke(User $admin, Lesson $lesson, string $note): Lesson
    {
        if ($admin->role !== Role::Admin || $admin->status !== UserStatus::Active) {
            throw new AttendanceException('Only an active admin may force-complete a lesson.');
        }

        $note = trim($note);

        if ($note === '') {
            throw new AttendanceException('A note is required to force-complete a lesson.');
        }

        if (! in_array($lesson->status, [LessonStatus::InProgress, LessonStatus::Confirmed], true)) {
            throw new AttendanceException('Only an in-progress or confirmed lesson can be force-completed.');
        }

        return DB::transaction(function () use ($admin, $lesson, $note): Lesson {
            $before = $lesson->status->value;

            if ($lesson->status === LessonStatus::Confirmed) {
                $lesson = LessonStateMachine::transition($lesson, LessonStatus::InProgress, function (Lesson $locked): void {
                    if ($locked->ends_at->isFuture()) {
                        throw new AttendanceException('This lesson has not ended yet; it cannot be force-completed.');
                    }
                });
            }

            $lesson = LessonStateMachine::transition($lesson, LessonStatus::Completed, function (Lesson $locked): void {
                if ($locked->ends_at->isFuture()) {
                    throw new AttendanceException('This lesson has not ended yet; it cannot be force-completed.');
                }

                $locked->forceFill([
                    'completed_at' => now(),
                    'report_due_at' => now()->addHours((int) Settings::get('report_due_hours')),
                    'auto_release_at' => now()->addHours((int) Settings::get('auto_release_hours')),
                ]);
            });

            app(RecordAuditLog::class)($admin, 'lesson.force_complete', $lesson, ['status' => $before], ['status' => $lesson->status->value, 'note' => $note]);

            return $lesson;
        });
    }
}
