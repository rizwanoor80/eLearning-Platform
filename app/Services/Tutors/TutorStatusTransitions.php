<?php

namespace App\Services\Tutors;

use App\Enums\TutorProfileStatus;
use App\Exceptions\TutorStatusTransitionException;
use App\Models\TutorProfile;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The one table of allowed tutor-status edges (R36) — the analogue, for
 * `TutorProfile`, of the lesson state machine in invariant #2. Every action
 * that moves a tutor's status asserts its edge here, inside a transaction
 * on a locked row, so two admins acting at once cannot both pass a
 * check-then-write.
 *
 *   draft             -> pending_review                          (tutor submits)
 *   changes_requested -> pending_review                          (tutor resubmits)
 *                      -> changes_requested | rejected           (admin)
 *   pending_review    -> approved | changes_requested | rejected (admin)
 *   approved          -> suspended                               (admin)
 *                      -> changes_requested                      (admin, or re-vetting: a rejected document / a new required document type)
 *   suspended         -> approved | changes_requested            (admin reinstates)
 *   rejected          -> (none)
 */
final class TutorStatusTransitions
{
    /**
     * @return array<string, array<int, TutorProfileStatus>> keyed by the `from` status value
     */
    public static function edges(): array
    {
        return [
            TutorProfileStatus::Draft->value => [TutorProfileStatus::PendingReview],
            TutorProfileStatus::PendingReview->value => [
                TutorProfileStatus::Approved, TutorProfileStatus::ChangesRequested, TutorProfileStatus::Rejected,
            ],
            TutorProfileStatus::ChangesRequested->value => [
                TutorProfileStatus::PendingReview, TutorProfileStatus::ChangesRequested, TutorProfileStatus::Rejected,
            ],
            TutorProfileStatus::Approved->value => [TutorProfileStatus::Suspended, TutorProfileStatus::ChangesRequested],
            TutorProfileStatus::Suspended->value => [TutorProfileStatus::Approved, TutorProfileStatus::ChangesRequested],
            TutorProfileStatus::Rejected->value => [],
        ];
    }

    public static function allows(TutorProfileStatus $from, TutorProfileStatus $to): bool
    {
        return in_array($to, self::edges()[$from->value], true);
    }

    /**
     * @throws TutorStatusTransitionException
     */
    public static function assert(TutorProfileStatus $from, TutorProfileStatus $to, ?string $message = null): void
    {
        if (! self::allows($from, $to)) {
            throw new TutorStatusTransitionException(
                $message ?? sprintf('A tutor cannot move from %s to %s.', $from->value, $to->value),
            );
        }
    }

    /**
     * Runs `$work` in a transaction on the profile row locked for update,
     * after asserting the edge on the locked (not the in-memory) status.
     * The caller's model is synced to the locked row first, so its
     * attributes are current when `$work` mutates and saves it.
     * The caller dispatches events after this returns — after commit.
     *
     * @template T
     *
     * @param  Closure(TutorProfile): T  $work
     * @return T
     *
     * @throws TutorStatusTransitionException
     */
    public static function transition(TutorProfile $profile, TutorProfileStatus $to, ?string $message, Closure $work): mixed
    {
        return DB::transaction(function () use ($profile, $to, $message, $work) {
            $locked = TutorProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();
            $profile->setRawAttributes($locked->getAttributes(), true);

            self::assert($profile->status, $to, $message);

            return $work($profile);
        });
    }
}
