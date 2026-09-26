<?php

namespace App\Actions\Lessons;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Services\Lessons\LessonSettlement;
use App\Services\Lessons\LessonStateMachine;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\DB;

/**
 * What happens to a lesson when its scheduled time is over and nobody has ruled on it (PRD §9, §4):
 *
 *  - `in_progress`, both sides joined, past the scheduled end: `completed`, with the report due
 *    `report_due_hours` after the end. Money stays in escrow until the report (`SubmitProgressReport`) or the 72h release (`AutoReleaseLesson`).
 *  - `confirmed`, nobody joined, past the end plus the room's close grace: `no_show_both`, then
 *    `refunded` — full refund, no strike, no tutor pay.
 *
 * A lesson where only one side joined stays `in_progress`: whether the other side was absent is the
 * joined party's call (`MarkNoShow`), never the clock's. Anything else is left untouched. The state
 * machine asserts the edge on the locked status, so a run that races another action or a second run
 * is a no-op.
 */
class SettleEndedLesson
{
    public function __construct(private LessonSettlement $settlement) {}

    /**
     * @return LessonStatus|null the status the lesson moved to, or null when nothing applied
     */
    public function __invoke(Lesson $lesson): ?LessonStatus
    {
        return match ($lesson->status) {
            LessonStatus::InProgress => $this->complete($lesson),
            LessonStatus::Confirmed => $this->unattended($lesson),
            default => null,
        };
    }

    private function complete(Lesson $lesson): ?LessonStatus
    {
        if ($lesson->ends_at->isFuture() || $lesson->tutor_joined_at === null || $lesson->learner_joined_at === null) {
            return null;
        }

        LessonStateMachine::transition($lesson, LessonStatus::Completed, function (Lesson $locked): void {
            $locked->forceFill([
                'completed_at' => now(),
                'report_due_at' => $locked->ends_at->copy()->addHours((int) Settings::get('report_due_hours')),
                // Frozen here like the report deadline: a later settings change never moves an existing lesson.
                'auto_release_at' => $locked->ends_at->copy()->addHours((int) Settings::get('auto_release_hours')),
            ]);
        });

        return LessonStatus::Completed;
    }

    private function unattended(Lesson $lesson): ?LessonStatus
    {
        if ($lesson->ends_at->copy()->addMinutes((int) config('video.close_grace_minutes'))->isFuture()) {
            return null;
        }

        DB::transaction(function () use ($lesson): void {
            $lesson = LessonStateMachine::transition($lesson, LessonStatus::NoShowBoth);

            LessonStateMachine::transition($lesson, LessonStatus::Refunded, function (Lesson $locked): void {
                $this->settlement->refundParent($locked, null);
            });
        });

        return LessonStatus::Refunded;
    }
}
