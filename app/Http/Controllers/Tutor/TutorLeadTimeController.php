<?php

namespace App\Http\Controllers\Tutor;

use App\Enums\TutorProfileStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tutor\LeadTimeRequest;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * R179: an approved tutor's way to change how soon a parent can book them. The onboarding wizard is
 * locked once a profile is submitted (`guardStepNotAhead()` → 409), and approved tutors are the only
 * ones who are bookable, so the Profile step alone would leave the setting unreachable for them.
 * Changing it never touches a lesson already booked: the value is read only when a new booking is validated.
 */
class TutorLeadTimeController extends Controller
{
    public function update(LeadTimeRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var TutorProfile|null $profile */
        $profile = $user->tutorProfile;

        abort_unless($profile !== null && $profile->status === TutorProfileStatus::Approved, 403);

        $profile->update(['min_lead_hours' => (int) $request->validated('min_lead_hours')]);

        return redirect()->route('tutor.dashboard');
    }
}
