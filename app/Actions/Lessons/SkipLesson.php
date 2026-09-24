<?php

namespace App\Actions\Lessons;

use App\Actions\Tutor\SuspendTutorForStrikes;
use App\Enums\LessonStatus;
use App\Enums\StrikeType;
use App\Exceptions\CancellationException;
use App\Exceptions\LedgerException;
use App\Models\Lesson;
use App\Models\TutorStrike;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Skips a `reserved` (unpaid) lesson — PRD §4: free either way, no ledger
 * movement (a `reserved` lesson has never been HOLD-ed), and no strike
 * unless the tutor skips inside the lesson's own frozen
 * `cancel_window_hours`. An already-moved-on lesson is rejected by
 * `LessonStateMachine` itself with `LessonTransitionException`.
 */
class SkipLesson
{
    public function __construct(private SuspendTutorForStrikes $suspendTutorForStrikes) {}

    public function __invoke(User $actor, Lesson $lesson, ?string $reason = null): Lesson
    {
        $isTutor = $lesson->tutorProfile->user_id === $actor->id;
        $isParent = $lesson->learner->account_user_id === $actor->id;

        if (! $isTutor && ! $isParent) {
            throw new CancellationException("Only the lesson's own tutor or parent may skip it.");
        }

        if (now()->greaterThanOrEqualTo($lesson->starts_at)) {
            throw new CancellationException('This lesson has already started; it can no longer be skipped.');
        }

        $strikeCreated = false;

        $to = $isTutor ? LessonStatus::CancelledByTutor : LessonStatus::CancelledByParent;

        $lesson = DB::transaction(function () use ($lesson, $to, $actor, $reason, $isTutor, &$strikeCreated) {
            return LessonStateMachine::transition(
                $lesson,
                $to,
                function (Lesson $locked) use ($actor, $reason, $isTutor, &$strikeCreated) {
                    if ($locked->status !== LessonStatus::Reserved) {
                        throw new CancellationException("Lesson {$locked->id} is not reserved; it cannot be skipped.");
                    }

                    if (app(LedgerService::class)->sum($locked) !== 0) {
                        throw new LedgerException("Lesson {$locked->id} is reserved but already has ledger activity.");
                    }

                    $locked->forceFill([
                        'cancelled_at' => now(),
                        'cancelled_by_user_id' => $actor->id,
                        'cancel_reason' => $reason,
                    ]);

                    if ($isTutor && $this->insideWindow($locked)) {
                        TutorStrike::query()->create([
                            'tutor_profile_id' => $locked->tutor_profile_id,
                            'lesson_id' => $locked->id,
                            'type' => StrikeType::LateCancel,
                            'note' => "Tutor skipped inside the {$locked->cancel_window_hours}h window.",
                        ]);

                        $strikeCreated = true;
                    }
                },
            );
        });

        if ($strikeCreated) {
            $this->suspendTutorForStrikesQuietly($lesson);
        }

        return $lesson;
    }

    private function insideWindow(Lesson $lesson): bool
    {
        $deadline = $lesson->starts_at->copy()->subHours($lesson->cancel_window_hours);

        return now()->greaterThan($deadline);
    }

    /**
     * The skip itself has already committed by the time this runs; a failure
     * here must not surface as if the skip failed. Logged for reconciliation
     * (CP8 hardening — see STATUS.md `## Deferred`), not rethrown.
     */
    private function suspendTutorForStrikesQuietly(Lesson $lesson): void
    {
        try {
            ($this->suspendTutorForStrikes)($lesson->tutorProfile);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
