<?php

namespace App\Actions\Reviews;

use App\Enums\Role;
use App\Exceptions\ReviewException;
use App\Models\Lesson;
use App\Models\Review;
use App\Models\User;
use App\Support\Messaging\MessageMasker;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CP7 8c (R136): the account holder rates a lesson once. Eligible means the lesson reached
 * `completed` with both parties present — written out in full, not inferred from status alone,
 * because a future force-complete or an inconsistent fixture could otherwise slip through:
 * `completed_at IS NOT NULL AND tutor_joined_at IS NOT NULL AND learner_joined_at IS NOT NULL`.
 * A lesson that reached `completed_reported` via a no-show or a late parent cancellation never sets
 * `completed_at` (only `SettleEndedLesson`'s `completed` transition does), so it never qualifies —
 * that is the whole test, logged as a DECISION rather than re-derived at each call site.
 *
 * `comment` is masked unconditionally (never gated on `contactIsVisible()` the way messages are,
 * because a review is public from the moment it is published) and the masker's fail-closed
 * `RuntimeException` is converted here into a `ReviewException`, so a comment that cannot be masked
 * is refused with a redirect and a flash toast, never stored unmasked and never a 500.
 *
 * The unique index on `reviews.lesson_id` is the real enforcement against a double review; the
 * existence pre-check below is UX only — a concurrent second submit still finds the row and is
 * refused, converted from the database's unique-violation, not a race the code has to detect itself.
 *
 * `rating_avg`/`rating_count` are recomputed from every published review in the same transaction,
 * on a `lockForUpdate()`-locked `tutor_profiles` row, so two reviews landing at once still add up —
 * never incremented, following `ReviewLateReports`'s locked-recompute pattern.
 */
class SubmitReview
{
    public function __construct(private readonly MessageMasker $masker) {}

    /**
     * @throws ReviewException
     */
    public function __invoke(User $actor, Lesson $lesson, int $rating, ?string $comment): Review
    {
        if ($rating < 1 || $rating > 5) {
            throw new ReviewException('A rating must be between 1 and 5.');
        }

        $masked = null;

        if (filled($comment)) {
            try {
                $masked = $this->masker->mask($comment)->text;
            } catch (RuntimeException) {
                throw new ReviewException('The comment could not be checked, so it was not stored.');
            }
        }

        return DB::transaction(function () use ($actor, $lesson, $rating, $masked): Review {
            // The row lock is taken here, so eligibility and the duplicate check are decided on the
            // database's status, not a possibly-stale in-memory model.
            $locked = Lesson::query()->whereKey($lesson->getKey())->lockForUpdate()->firstOrFail();

            $problem = self::eligibilityProblem($actor, $locked);

            if ($problem !== null) {
                throw new ReviewException($problem);
            }

            try {
                $review = Review::query()->create([
                    'lesson_id' => $locked->id,
                    'tutor_profile_id' => $locked->tutor_profile_id,
                    'account_user_id' => $actor->id,
                    'rating' => $rating,
                    'comment' => $masked,
                    'published_at' => now(),
                ]);
            } catch (QueryException $e) {
                if ((string) $e->getCode() === '23505') {
                    throw new ReviewException('A review has already been submitted for this lesson.');
                }

                throw $e;
            }

            RecomputeTutorRating::lockedRecompute($locked->tutor_profile_id);

            return $review;
        });
    }

    /**
     * Why `$actor` cannot review `$lesson` right now, or null when they can. The review page reads
     * this too; the action re-checks it on the locked row, so it is a convenience there, never the
     * authority.
     */
    public static function eligibilityProblem(User $actor, Lesson $lesson): ?string
    {
        if ($actor->role !== Role::AccountOwner || $lesson->learner->account_user_id !== $actor->id) {
            return 'Only the family that booked this lesson can review it.';
        }

        if ($lesson->review()->exists()) {
            return 'A review has already been submitted for this lesson.';
        }

        $eligible = $lesson->completed_at !== null
            && $lesson->tutor_joined_at !== null
            && $lesson->learner_joined_at !== null;

        return $eligible ? null : 'This lesson cannot be reviewed.';
    }
}
