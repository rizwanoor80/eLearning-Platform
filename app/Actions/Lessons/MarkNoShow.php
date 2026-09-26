<?php

namespace App\Actions\Lessons;

use App\Actions\Tutor\SuspendTutorForStrikes;
use App\Enums\LessonStatus;
use App\Enums\StrikeType;
use App\Enums\VideoParticipant;
use App\Exceptions\AttendanceException;
use App\Exceptions\LessonTransitionException;
use App\Models\Lesson;
use App\Models\TutorStrike;
use App\Models\User;
use App\Services\Lessons\LessonParties;
use App\Services\Lessons\LessonSettlement;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A party marks the other as absent (PRD §4). Marking is manual: nothing in the system decides a
 * no-show on its own authority when one side joined.
 *
 *  - The tutor marks the student, once the lesson's frozen `student_grace_min` has passed since the
 *    start and the tutor is in and the learner is not: the tutor is paid in full, the parent is not
 *    refunded, no strike. `no_show_student -> completed_reported` in one transaction.
 *  - The parent marks the tutor, once `tutor_grace_min` has passed and the parent is in and the
 *    tutor is not: full refund, a `no_show` strike, no tutor pay. `no_show_tutor -> refunded`.
 *
 * The grace minutes come from the lesson row (invariant 11). The join facts are read on the locked
 * row, so a join that lands a moment before the mark wins. Refund-to-parent and pay-to-tutor are
 * chosen here per outcome, not inferred from each other (invariant 13).
 */
class MarkNoShow
{
    public function __construct(
        private LessonSettlement $settlement,
        private SuspendTutorForStrikes $suspendTutorForStrikes,
    ) {}

    /**
     * @throws AttendanceException
     * @throws LessonTransitionException
     */
    public function __invoke(User $actor, Lesson $lesson): Lesson
    {
        $party = LessonParties::participantFor($lesson, $actor)
            ?? throw new AttendanceException('Only the tutor or the parent of this lesson may mark a no-show.');

        if ($lesson->status !== LessonStatus::InProgress) {
            throw new AttendanceException('A no-show can only be marked once one side has joined and the lesson is in progress.');
        }

        return $party === VideoParticipant::Tutor
            ? $this->student($actor, $lesson)
            : $this->tutor($actor, $lesson);
    }

    private function student(User $actor, Lesson $lesson): Lesson
    {
        return DB::transaction(function () use ($actor, $lesson): Lesson {
            $lesson = LessonStateMachine::transition($lesson, LessonStatus::NoShowStudent, function (Lesson $locked): void {
                $this->assertMarkable($locked, $locked->student_grace_min, present: 'tutor_joined_at', absent: 'learner_joined_at', absentName: 'The student');
            });

            return LessonStateMachine::transition($lesson, LessonStatus::CompletedReported, function (Lesson $locked) use ($actor): void {
                $this->settlement->payTutor($locked, $actor);
            });
        });
    }

    private function tutor(User $actor, Lesson $lesson): Lesson
    {
        $lesson = DB::transaction(function () use ($actor, $lesson): Lesson {
            $lesson = LessonStateMachine::transition($lesson, LessonStatus::NoShowTutor, function (Lesson $locked): void {
                $this->assertMarkable($locked, $locked->tutor_grace_min, present: 'learner_joined_at', absent: 'tutor_joined_at', absentName: 'The tutor');
            });

            return LessonStateMachine::transition($lesson, LessonStatus::Refunded, function (Lesson $locked) use ($actor): void {
                $this->settlement->refundParent($locked, $actor);

                TutorStrike::query()->create([
                    'tutor_profile_id' => $locked->tutor_profile_id,
                    'lesson_id' => $locked->id,
                    'type' => StrikeType::NoShow,
                    'note' => "Tutor did not join within {$locked->tutor_grace_min} minutes of the start.",
                ]);
            });
        });

        // The mark has committed; a failed suspension check must not read as a failed mark (as in CancelLesson).
        try {
            ($this->suspendTutorForStrikes)($lesson->tutorProfile);
        } catch (Throwable $e) {
            report($e);
        }

        return $lesson;
    }

    /**
     * @throws AttendanceException
     */
    private function assertMarkable(Lesson $locked, int $graceMinutes, string $present, string $absent, string $absentName): void
    {
        if ($locked->{$present} === null) {
            throw new AttendanceException('You have not joined this lesson, so you cannot mark the other party as absent.');
        }

        if ($locked->{$absent} !== null) {
            throw new AttendanceException("{$absentName} joined this lesson.");
        }

        if (now()->lessThan($locked->starts_at->copy()->addMinutes($graceMinutes))) {
            throw new AttendanceException("A no-show can be marked {$graceMinutes} minutes after the lesson starts.");
        }
    }
}
