<?php

namespace App\Actions\Learner;

use App\Enums\LessonStatus;
use App\Exceptions\LearnerDeletionException;
use App\Models\Learner;

/**
 * Soft delete. The adult student's own learner can never be removed. Refused
 * (naming the blocking lessons, R54) while any of the learner's lessons is
 * still non-terminal per `LessonStatus::isTerminal()` — money or an
 * obligation is still open on it. A weekly recurring slot has no lessons of
 * its own status to block on; CP4 revisits this guard if that changes.
 */
class DeleteLearner
{
    public function __invoke(Learner $learner): void
    {
        if ($learner->isSelf()) {
            throw new LearnerDeletionException('An adult student cannot remove their own learner profile.');
        }

        $blocking = $learner->lessons()
            ->whereNotIn('status', LessonStatus::terminalValues())
            ->orderBy('starts_at')
            ->get(['id', 'starts_at']);

        if ($blocking->isNotEmpty()) {
            $names = $blocking->map(fn ($lesson) => "#{$lesson->id} ({$lesson->starts_at->toDateTimeString()} UTC)")->implode(', ');

            throw new LearnerDeletionException("Cannot delete this learner: still-open lessons {$names}.");
        }

        $learner->delete();
    }
}
