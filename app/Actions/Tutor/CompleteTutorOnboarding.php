<?php

namespace App\Actions\Tutor;

use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;

class CompleteTutorOnboarding
{
    /**
     * Submits a draft profile for admin review. Callers must have already
     * confirmed the wizard's derived step is `complete` — this action does
     * not re-derive it, so it can be unit-tested in isolation.
     */
    public function __invoke(TutorProfile $profile): void
    {
        $profile->update(['status' => TutorProfileStatus::PendingReview]);
    }
}
