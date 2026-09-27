<?php

namespace App\Actions\Reviews;

use App\Models\Review;
use App\Models\TutorProfile;

/**
 * `rating_avg`/`rating_count` recomputed from every currently-published review, never incremented —
 * shared by `SubmitReview` and the admin unpublish/republish actions so a submit, an unpublish and a
 * republish landing at once still add up to the same truth. Always called from inside a caller's own
 * `DB::transaction()`: the `lockForUpdate()` here only holds within that surrounding transaction, and
 * is the thing that makes two callers racing on the same tutor serialise instead of losing an update.
 */
class RecomputeTutorRating
{
    public static function lockedRecompute(int $tutorProfileId): TutorProfile
    {
        $locked = TutorProfile::query()->whereKey($tutorProfileId)->lockForUpdate()->firstOrFail();

        // `toBase()` drops to the plain query builder, returning a stdClass row rather than a
        // `Review` model — `review_count`/`review_average` are aggregate aliases, not real columns,
        // and Larastan flags them as undefined properties on the model.
        $aggregate = Review::query()
            ->where('tutor_profile_id', $tutorProfileId)
            ->whereNotNull('published_at')
            ->toBase()
            ->selectRaw('COUNT(*) as review_count, COALESCE(AVG(rating), 0) as review_average')
            ->first();

        $locked->forceFill([
            'rating_count' => (int) ($aggregate->review_count ?? 0),
            'rating_avg' => round((float) ($aggregate->review_average ?? 0), 2),
        ])->save();

        return $locked;
    }
}
