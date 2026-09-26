<?php

namespace App\Actions\Lessons;

use App\Enums\LessonStatus;
use App\Enums\VideoParticipant;
use App\Models\Lesson;
use App\Services\Lessons\LessonStateMachine;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Records that one side joined: the one write behind both the provider webhook and the manual
 * "I've joined". The join time is set only if it is still null, so a repeat, a redelivery or a
 * second event changes nothing. The first join moves a `confirmed` lesson to `in_progress`; a
 * lesson in any other status is left as it is (a late event for a lesson already settled). A join at
 * or after the scheduled end is not attendance: the room stays open ten minutes longer, but someone
 * who turns up after the lesson was over must not turn a no-show into a paid or struck outcome.
 */
class RecordAttendance
{
    /**
     * @return bool whether this call set the join time
     */
    public function __invoke(Lesson $lesson, VideoParticipant $participant, CarbonInterface $at): bool
    {
        return DB::transaction(function () use ($lesson, $participant, $at): bool {
            $locked = Lesson::query()->whereKey($lesson->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [LessonStatus::Confirmed, LessonStatus::InProgress], true)) {
                return false;
            }

            if ($at->greaterThanOrEqualTo($locked->ends_at)) {
                return false;
            }

            $column = $participant === VideoParticipant::Tutor ? 'tutor_joined_at' : 'learner_joined_at';

            if ($locked->{$column} !== null) {
                return false;
            }

            if ($locked->status === LessonStatus::Confirmed) {
                LessonStateMachine::transition($locked, LessonStatus::InProgress, fn (Lesson $l) => $l->forceFill([$column => $at]));
            } else {
                $locked->forceFill([$column => $at])->save();
            }

            return true;
        });
    }
}
