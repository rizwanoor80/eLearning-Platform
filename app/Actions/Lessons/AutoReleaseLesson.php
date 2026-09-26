<?php

namespace App\Actions\Lessons;

use App\Enums\LessonStatus;
use App\Exceptions\LessonTransitionException;
use App\Models\Lesson;
use App\Services\Lessons\LessonSettlement;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The 72 h backstop (PRD §2.7 rule 5): a `completed` lesson whose tutor filed no report by
 * `auto_release_at` has its escrow released to the tutor anyway, `completed -> completed_reported`, and
 * the lesson carries the late flag (`report_late_at`). Same edge and same ledger call as
 * `SubmitProgressReport`, so whichever runs second finds the lesson already moved and does nothing: the
 * release happens once. The tutor's third late flag in 90 days goes to `ReviewLateReports` afterwards.
 */
class AutoReleaseLesson
{
    public function __construct(private LessonSettlement $settlement, private ReviewLateReports $review) {}

    /**
     * @return bool true when this call released the lesson
     *
     * @throws LessonTransitionException when the lesson is no longer `completed` (a report or a dispute got there first)
     */
    public function __invoke(Lesson $lesson): bool
    {
        LessonStateMachine::transition($lesson, LessonStatus::CompletedReported, function (Lesson $locked): void {
            $this->settlement->payTutor($locked, null);

            // A lesson completed before the deadline was frozen never had a report form to be late for.
            if ($locked->auto_release_at !== null) {
                $locked->forceFill(['report_late_at' => now()]);
            }
        });

        // The release has committed; a failed review check must not read as a failed release.
        try {
            ($this->review)($lesson->tutorProfile()->firstOrFail());
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }

    /**
     * The lessons due: `completed`, not yet paid out, past the deadline frozen on the lesson. A lesson
     * completed before `auto_release_at` existed falls back to the end plus the current setting.
     *
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    public static function due($query, int $fallbackHours)
    {
        return $query
            ->where('status', LessonStatus::Completed)
            ->whereNull('escrow_released_at')
            ->where(function ($q) use ($fallbackHours): void {
                $q->where('auto_release_at', '<=', now())
                    ->orWhere(fn ($legacy) => $legacy->whereNull('auto_release_at')
                        ->where('ends_at', '<=', now()->subHours($fallbackHours)));
            });
    }
}
