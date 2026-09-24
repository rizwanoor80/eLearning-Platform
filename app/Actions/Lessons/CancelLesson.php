<?php

namespace App\Actions\Lessons;

use App\Actions\Tutor\SuspendTutorForStrikes;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\StrikeType;
use App\Exceptions\CancellationException;
use App\Models\Lesson;
use App\Models\TutorStrike;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cancels a `confirmed` lesson (PRD §4). Only the lesson's own tutor or the
 * booking parent may call this, and only before the lesson has started — a
 * confirmed lesson past its start is a no-show/join matter (CP4), not a
 * cancellation. An already-cancelled (or otherwise moved-on) lesson is
 * rejected by `LessonStateMachine` itself with `LessonTransitionException`.
 *
 * Parent cancelling on/after the lesson's own frozen `cancel_window_hours`
 * deadline: full refund (REFUND). Parent cancelling inside that window: the
 * tutor is paid in full (RELEASE), reported for CP4's report flow. Tutor
 * cancelling at any time: full refund to the parent (REFUND); a strike if
 * the cancellation lands inside the window (invariant #11 — the window
 * comes from the lesson row, never from settings).
 */
class CancelLesson
{
    public function __construct(private SuspendTutorForStrikes $suspendTutorForStrikes) {}

    public function __invoke(User $actor, Lesson $lesson, ?string $reason = null): Lesson
    {
        $isTutor = $lesson->tutorProfile->user_id === $actor->id;
        $isParent = $lesson->learner->account_user_id === $actor->id;

        if (! $isTutor && ! $isParent) {
            throw new CancellationException("Only the lesson's own tutor or parent may cancel it.");
        }

        if (now()->greaterThanOrEqualTo($lesson->starts_at)) {
            throw new CancellationException('This lesson has already started; it can no longer be cancelled.');
        }

        return $isTutor
            ? $this->cancelByTutor($actor, $lesson, $reason)
            : $this->cancelByParent($actor, $lesson, $reason);
    }

    private function cancelByTutor(User $actor, Lesson $lesson, ?string $reason): Lesson
    {
        $strikeCreated = false;

        $lesson = DB::transaction(function () use ($lesson, $actor, $reason, &$strikeCreated) {
            return LessonStateMachine::transition(
                $lesson,
                LessonStatus::CancelledByTutor,
                function (Lesson $locked) use ($actor, $reason, &$strikeCreated) {
                    if ($locked->status !== LessonStatus::Confirmed) {
                        throw new CancellationException("Lesson {$locked->id} is not confirmed; it cannot be cancelled.");
                    }

                    app(LedgerService::class)->refund($locked, $actor);
                    $this->markPaymentRefunded($locked);

                    $locked->forceFill([
                        'cancelled_at' => now(),
                        'cancelled_by_user_id' => $actor->id,
                        'cancel_reason' => $reason,
                    ]);

                    if ($this->insideWindow($locked)) {
                        TutorStrike::query()->create([
                            'tutor_profile_id' => $locked->tutor_profile_id,
                            'lesson_id' => $locked->id,
                            'type' => StrikeType::LateCancel,
                            'note' => "Tutor cancelled inside the {$locked->cancel_window_hours}h window.",
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

    private function cancelByParent(User $actor, Lesson $lesson, ?string $reason): Lesson
    {
        return DB::transaction(function () use ($lesson, $actor, $reason) {
            $insideWindow = null;

            $lesson = LessonStateMachine::transition(
                $lesson,
                LessonStatus::CancelledByParent,
                function (Lesson $locked) use ($actor, $reason, &$insideWindow) {
                    if ($locked->status !== LessonStatus::Confirmed) {
                        throw new CancellationException("Lesson {$locked->id} is not confirmed; it cannot be cancelled.");
                    }

                    $insideWindow = $this->insideWindow($locked);

                    $locked->forceFill([
                        'cancelled_at' => now(),
                        'cancelled_by_user_id' => $actor->id,
                        'cancel_reason' => $reason,
                    ]);
                },
            );

            if ($insideWindow) {
                return LessonStateMachine::transition(
                    $lesson,
                    LessonStatus::CompletedReported,
                    function (Lesson $locked) use ($actor) {
                        app(LedgerService::class)->release($locked, $actor);

                        $locked->forceFill(['escrow_released_at' => now()]);
                    },
                );
            }

            return LessonStateMachine::transition(
                $lesson,
                LessonStatus::Refunded,
                function (Lesson $locked) use ($actor) {
                    app(LedgerService::class)->refund($locked, $actor);
                    $this->markPaymentRefunded($locked);
                },
            );
        });
    }

    private function insideWindow(Lesson $lesson): bool
    {
        $deadline = $lesson->starts_at->copy()->subHours($lesson->cancel_window_hours);

        return now()->greaterThan($deadline);
    }

    private function markPaymentRefunded(Lesson $lesson): void
    {
        $lesson->payment?->update([
            'status' => PaymentStatus::Refunded,
            'refunded_amount' => $lesson->price,
        ]);
    }

    /**
     * The cancellation itself has already committed by the time this runs; a
     * failure here must not surface as if the cancellation failed. Logged for
     * reconciliation (CP8 hardening — see STATUS.md `## Deferred`), not rethrown.
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
