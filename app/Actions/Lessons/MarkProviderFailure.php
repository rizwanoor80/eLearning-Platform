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
use App\Services\Lessons\LessonSettlement;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Admin only (PRD §4, §12): the video provider was down for the lesson window. Full refund to the
 * parent, no strike, no tutor pay, no rating — the ledger `refund` and the status commit together
 * with an audit row carrying the required note. The gateway refund call is CP5's.
 *
 * Only a `confirmed` lesson (nobody has joined) can be marked: the state machine has no
 * `in_progress -> provider_failure` edge and this cycle does not change it (STATUS §6). The window
 * must have begun; a lesson that has not started has no window to have failed.
 */
class MarkProviderFailure
{
    public function __construct(private LessonSettlement $settlement) {}

    /**
     * @throws AttendanceException
     * @throws LessonTransitionException
     */
    public function __invoke(User $admin, Lesson $lesson, string $note): Lesson
    {
        if ($admin->role !== Role::Admin || $admin->status !== UserStatus::Active) {
            throw new AttendanceException('Only an active admin may mark a provider failure.');
        }

        $note = trim($note);

        if ($note === '') {
            throw new AttendanceException('A note is required to mark a provider failure.');
        }

        return DB::transaction(function () use ($admin, $lesson, $note): Lesson {
            $before = $lesson->status->value;

            $lesson = LessonStateMachine::transition($lesson, LessonStatus::ProviderFailure, function (Lesson $locked) use ($admin): void {
                if ($locked->starts_at->isFuture()) {
                    throw new AttendanceException('This lesson has not started, so its video window cannot have failed.');
                }

                $this->settlement->refundParent($locked, $admin);
            });

            app(RecordAuditLog::class)($admin, 'lesson.provider_failure', $lesson, ['status' => $before], ['status' => $lesson->status->value, 'note' => $note]);

            return $lesson;
        });
    }
}
