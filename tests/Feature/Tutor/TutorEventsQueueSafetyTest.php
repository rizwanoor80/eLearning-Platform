<?php

use App\Events\Tutor\TutorApproved;
use App\Events\Tutor\TutorChangesRequested;
use App\Events\Tutor\TutorPermitExpired;
use App\Events\Tutor\TutorPermitExpiring;
use App\Events\Tutor\TutorRejected;
use App\Events\Tutor\TutorSubmittedForReview;
use Illuminate\Queue\SerializesModels;

// R28: an event carrying a TutorProfile into a queued listener must serialize the
// model by key, so the worker re-fetches fresh state instead of a stale snapshot.
it('serializes the tutor profile by key on every tutor event', function (string $event) {
    expect(class_uses_recursive($event))->toContain(SerializesModels::class);
})->with([
    TutorSubmittedForReview::class,
    TutorChangesRequested::class,
    TutorApproved::class,
    TutorRejected::class,
    TutorPermitExpiring::class,
    TutorPermitExpired::class,
]);
