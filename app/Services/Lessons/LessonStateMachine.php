<?php

namespace App\Services\Lessons;

use App\Enums\LessonStatus;
use App\Events\Lessons\LessonStatusChanged;
use App\Exceptions\LessonTransitionException;
use App\Models\Lesson;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only place a lesson's status is written (invariant #2, R51). It declares
 * every state and every edge of DATA_MODEL's diagram — including the ones CP4–CP6
 * will call — and refuses everything else. An edge with no calling action yet is
 * reachable only from a test.
 *
 *   pending_payment    -> confirmed | expired
 *   reserved           -> confirmed | cancelled_payment_failed | cancelled_by_parent | cancelled_by_tutor
 *   confirmed          -> cancelled_by_parent | cancelled_by_tutor | in_progress | no_show_both | provider_failure
 *   in_progress        -> completed | no_show_student | no_show_tutor
 *   completed          -> completed_reported | disputed
 *   completed_reported -> disputed
 *   disputed           -> settled
 *   cancelled_by_parent-> refunded | completed_reported
 *   no_show_student    -> completed_reported          (auto-advance, tutor paid)
 *   no_show_tutor      -> refunded                    (auto-advance)
 *   no_show_both       -> refunded                    (auto-advance)
 *   expired, refunded, settled, cancelled_by_tutor, cancelled_payment_failed, provider_failure -> (none)
 *
 * Every change runs in one transaction on the lesson row locked for update, the
 * edge is asserted on the LOCKED status (a stale copy cannot pass a check the
 * database has moved past), the caller's `$work` — the ledger operation, the
 * timestamps — runs inside the same transaction, and the event goes out only
 * after the outermost commit. A `saving` hook on `Lesson` refuses any other
 * write to `status`.
 */
final class LessonStateMachine
{
    /**
     * @return array<string, array<int, LessonStatus>> keyed by the `from` status value
     */
    public static function edges(): array
    {
        return [
            LessonStatus::PendingPayment->value => [LessonStatus::Confirmed, LessonStatus::Expired],
            LessonStatus::Reserved->value => [
                LessonStatus::Confirmed, LessonStatus::CancelledPaymentFailed,
                LessonStatus::CancelledByParent, LessonStatus::CancelledByTutor,
            ],
            LessonStatus::Confirmed->value => [
                LessonStatus::CancelledByParent, LessonStatus::CancelledByTutor, LessonStatus::InProgress,
                LessonStatus::NoShowBoth, LessonStatus::ProviderFailure,
            ],
            LessonStatus::InProgress->value => [LessonStatus::Completed, LessonStatus::NoShowStudent, LessonStatus::NoShowTutor],
            LessonStatus::Completed->value => [LessonStatus::CompletedReported, LessonStatus::Disputed],
            LessonStatus::CompletedReported->value => [LessonStatus::Disputed],
            LessonStatus::Disputed->value => [LessonStatus::Settled],
            LessonStatus::CancelledByParent->value => [LessonStatus::Refunded, LessonStatus::CompletedReported],
            LessonStatus::NoShowStudent->value => [LessonStatus::CompletedReported],
            LessonStatus::NoShowTutor->value => [LessonStatus::Refunded],
            LessonStatus::NoShowBoth->value => [LessonStatus::Refunded],
            LessonStatus::Expired->value => [],
            LessonStatus::Refunded->value => [],
            LessonStatus::Settled->value => [],
            LessonStatus::CancelledByTutor->value => [],
            LessonStatus::CancelledPaymentFailed->value => [],
            LessonStatus::ProviderFailure->value => [],
        ];
    }

    /**
     * The two states a lesson can be created in (single booking, weekly slot).
     *
     * @return array<int, LessonStatus>
     */
    public static function initialStates(): array
    {
        return [LessonStatus::PendingPayment, LessonStatus::Reserved];
    }

    public static function allows(LessonStatus $from, LessonStatus $to): bool
    {
        return in_array($to, self::edges()[$from->value], true);
    }

    /**
     * @throws LessonTransitionException
     */
    public static function assert(LessonStatus $from, LessonStatus $to): void
    {
        if (! self::allows($from, $to)) {
            throw new LessonTransitionException(sprintf('A lesson cannot move from %s to %s.', $from->value, $to->value));
        }
    }

    /**
     * Creates a lesson in one of the two initial states. `$attributes` carry every
     * column but `status` (which this sets); the frozen price and policy values
     * are among them, decided by the caller (`BookLesson`, CP3 3c).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws LessonTransitionException
     * @throws QueryException a caller-visible DB constraint (e.g. the overlap
     *                        exclusion or the one-trial-per-pair partial unique index) rejected the
     *                        insert; this method does not translate it, the caller does
     */
    public static function open(array $attributes, LessonStatus $initial): Lesson
    {
        if (! in_array($initial, self::initialStates(), true)) {
            throw new LessonTransitionException(sprintf('A lesson cannot be created as %s.', $initial->value));
        }

        return DB::transaction(function () use ($attributes, $initial): Lesson {
            $lesson = new Lesson;
            $lesson->forceFill([...$attributes, 'status' => $initial]);
            Lesson::allowingStatusWrites(fn () => $lesson->save());

            DB::afterCommit(fn () => LessonStatusChanged::dispatch($lesson, null, $initial));

            return $lesson;
        });
    }

    /**
     * Moves the lesson along an allowed edge. `$work` runs inside the same
     * transaction, before the row is saved, with the caller's model synced to the
     * locked row — the place for the ledger operation and the timestamps of that
     * edge. If it throws, nothing (status, ledger, timestamps) is written.
     *
     * @param  Closure(Lesson): mixed|null  $work
     *
     * @throws LessonTransitionException
     */
    public static function transition(Lesson $lesson, LessonStatus $to, ?Closure $work = null): Lesson
    {
        return DB::transaction(function () use ($lesson, $to, $work): Lesson {
            $locked = Lesson::query()->whereKey($lesson->getKey())->lockForUpdate()->firstOrFail();
            $lesson->setRawAttributes($locked->getAttributes(), true);

            $from = $lesson->status;
            self::assert($from, $to);

            if ($work !== null) {
                $work($lesson);
            }

            $lesson->forceFill(['status' => $to]);
            Lesson::allowingStatusWrites(fn () => $lesson->save());

            DB::afterCommit(fn () => LessonStatusChanged::dispatch($lesson, $from, $to));

            return $lesson;
        });
    }
}
