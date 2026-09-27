<?php

namespace App\Actions\Reviews;

use App\Actions\RecordAuditLog;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Admin unpublish/republish (R136, PRD §12): `published_at` doubles as the state — nulled to
 * unpublish, reset to now() to republish — so no separate flag exists to drift from it. Each move
 * takes a required note (audited, never stored on the review itself) and recomputes the tutor's
 * aggregate on the same locked path `SubmitReview` uses, in the same transaction, so a review that
 * flips while a submit is in flight still lands on a consistent count.
 */
class SetReviewPublished
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @throws RuntimeException when the note is blank or the review is already in the target state
     */
    public function __invoke(User $actor, Review $review, bool $publish, string $note): Review
    {
        if (trim($note) === '') {
            throw new RuntimeException('A note is required to unpublish or republish a review.');
        }

        return DB::transaction(function () use ($actor, $review, $publish, $note): Review {
            $locked = Review::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isPublished() === $publish) {
                throw new RuntimeException($publish ? 'This review is already published.' : 'This review is already unpublished.');
            }

            $before = ['published_at' => $locked->published_at?->toIso8601String()];

            $locked->forceFill(['published_at' => $publish ? now() : null])->save();

            ($this->recordAuditLog)($actor, $publish ? 'review.republished' : 'review.unpublished', $locked, $before, [
                'published_at' => $locked->published_at?->toIso8601String(),
                'note' => $note,
            ]);

            RecomputeTutorRating::lockedRecompute($locked->tutor_profile_id);

            return $locked;
        });
    }
}
