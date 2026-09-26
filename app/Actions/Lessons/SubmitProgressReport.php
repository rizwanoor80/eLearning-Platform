<?php

namespace App\Actions\Lessons;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\VideoParticipant;
use App\Events\Lessons\ProgressReportSubmitted;
use App\Exceptions\LessonTransitionException;
use App\Exceptions\ProgressReportException;
use App\Models\Lesson;
use App\Models\ProgressReport;
use App\Models\User;
use App\Services\Lessons\LessonParties;
use App\Services\Lessons\LessonSettlement;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * The tutor's report on a lesson (PRD §2.7): it is stored, the tutor's escrow for the lesson is released,
 * and the parent is emailed (from the queued listener of `ProgressReportSubmitted`, after the commit).
 *
 * Release happens exactly once because the state-machine edge is the guard, not the report row:
 * `completed -> completed_reported` is asserted on the locked lesson, and the report row, the ledger
 * entries, `escrow_released_at` and the status commit together. A second submit, or a submit that loses a
 * race with the 72 h sweep, finds the lesson already moved and is refused; `progress_reports.lesson_id`
 * unique and `LedgerService::release()`'s escrow check are backstops, not the mechanism.
 *
 * One case takes a report without a release: a lesson the sweep already released (`report_late_at` set)
 * still gets its report, so the parent is not left without one. Only that — a lesson that reached
 * `completed_reported` through a no-show or a late cancellation never takes a report.
 */
class SubmitProgressReport
{
    public function __construct(private LessonSettlement $settlement) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ProgressReportException
     * @throws LessonTransitionException
     */
    public function __invoke(User $actor, Lesson $lesson, array $data): ProgressReport
    {
        $report = DB::transaction(function () use ($actor, $lesson, $data): ProgressReport {
            // The row lock is taken here, so the branch below is decided on the status the database has.
            $locked = Lesson::query()->whereKey($lesson->getKey())->lockForUpdate()->firstOrFail();

            $problem = self::problemFor($actor, $locked);

            if ($problem !== null) {
                throw new ProgressReportException($problem);
            }

            $fields = $this->fieldsFor($locked, $data);

            if ($locked->status === LessonStatus::CompletedReported) {
                return $this->store($locked, $fields);
            }

            $report = null;

            LessonStateMachine::transition($locked, LessonStatus::CompletedReported, function (Lesson $inTransition) use ($actor, $fields, &$report): void {
                $report = $this->store($inTransition, $fields);
                $this->settlement->payTutor($inTransition, $actor);
            });

            return $report;
        });

        DB::afterCommit(fn () => ProgressReportSubmitted::dispatch($report));

        return $report;
    }

    /**
     * Why `$actor` cannot file a report on `$lesson` right now, or null when they can. The lesson page
     * and the report page read this; the action re-checks it on the locked row, so they are a
     * convenience and never the authority.
     */
    public static function problemFor(User $actor, Lesson $lesson): ?string
    {
        if (LessonParties::participantFor($lesson, $actor) !== VideoParticipant::Tutor) {
            return 'Only the lesson’s tutor can write its report.';
        }

        if ($lesson->progressReport()->exists()) {
            return 'A report has already been submitted for this lesson.';
        }

        return match (true) {
            $lesson->status === LessonStatus::Completed => null,
            // Auto-released without a report: the report may still be filed, and no money moves.
            $lesson->status === LessonStatus::CompletedReported && $lesson->report_late_at !== null => null,
            $lesson->status === LessonStatus::Disputed => 'This lesson is under review, so a report cannot be submitted now.',
            default => 'This lesson is not waiting for a report.',
        };
    }

    /**
     * The columns to store, checked against the lesson's own type — never the client's: a trial takes the
     * three trial fields, a regular lesson none.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ProgressReportException
     */
    private function fieldsFor(Lesson $locked, array $data): array
    {
        $standard = ['topics_covered', 'went_well', 'work_on_next', 'homework', 'engagement'];
        $trial = ['trial_suitability', 'trial_recommended_frequency', 'trial_focus_areas'];
        $isTrial = $locked->type === LessonType::Trial;

        $missing = array_filter($isTrial ? [...$standard, ...$trial] : $standard, fn (string $key): bool => blank($data[$key] ?? null));

        if (! $isTrial && array_filter($trial, fn (string $key): bool => filled($data[$key] ?? null)) !== []) {
            throw new ProgressReportException('The trial fields belong to a trial lesson’s report only.');
        }

        if ($missing !== []) {
            throw new ProgressReportException('The report is missing: '.implode(', ', $missing).'.');
        }

        return array_intersect_key($data, array_flip($isTrial ? [...$standard, ...$trial] : $standard));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function store(Lesson $locked, array $fields): ProgressReport
    {
        return ProgressReport::query()->create([
            ...$fields,
            'lesson_id' => $locked->id,
            'tutor_profile_id' => $locked->tutor_profile_id,
            'submitted_at' => now(),
        ]);
    }
}
