<?php

namespace App\Actions\Learner;

use App\Actions\RecordAuditLog;
use App\Enums\LessonStatus;
use App\Exceptions\LearnerDeletionException;
use App\Models\Learner;
use App\Models\RecurringSlot;
use App\Models\User;

/**
 * Soft delete. The adult student's own learner can never be removed. Refused
 * (naming the blocking lessons, R54) while any of the learner's lessons is
 * still non-terminal per `LessonStatus::isTerminal()` — money or an
 * obligation is still open on it. Also refused while the learner has a weekly slot
 * that is `active` or `paused` (CP4 4d): the slot keeps generating lessons and
 * blocking the tutor's time until someone ends it, so it must be ended first.
 *
 * Guarded and audited either way. Called from two places: an admin deleting
 * a learner, and `LearnerController::destroy()` — the account owner's
 * existing self-service route, which predates R54. R54's own text reads
 * "Admin action, audited; not exposed to parents in CP3"; whether that line
 * governs this pre-existing parent-facing route is not settled here — see
 * STATUS.md §7 for the pending ruling.
 */
class DeleteLearner
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function __invoke(User $actor, Learner $learner): void
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

        $liveSlots = $learner->recurringSlots()->whereIn('status', RecurringSlot::holdingStatuses())->count();

        if ($liveSlots > 0) {
            throw new LearnerDeletionException('Cannot delete this learner: a weekly slot is still in place. End it first.');
        }

        $before = ['display_name' => $learner->display_name];

        $learner->delete();

        ($this->recordAuditLog)($actor, 'learner.deleted', $learner, $before, ['status' => 'deleted']);
    }
}
