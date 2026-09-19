<?php

namespace App\Http\Controllers\Tutor;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Scheduling\SlotCalculator;
use App\Services\Search\TutorPresenter;
use App\Support\Facades\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public tutor profile. Only a bookable tutor has one: a suspended, expired,
 * unapproved or unknown id is the same 404, so the page reveals nothing about
 * tutors who are not on offer.
 */
class TutorProfileController extends Controller
{
    public function __invoke(Request $request, int $tutor, SlotCalculator $calculator, TutorPresenter $presenter): Response
    {
        $profile = TutorProfile::query()
            ->bookable()
            ->with(['user:id,name,timezone', 'tutorSubjects.curriculum:id,name', 'tutorSubjects.subject:id,name'])
            ->findOrFail($tutor);

        $user = $request->user();
        $timezone = $user instanceof User ? $user->timezone : (string) Settings::get('default_timezone');

        return Inertia::render('tutors/Show', [
            'tutor' => $presenter->profile($profile, $calculator->forTutor($profile, $timezone), EnsureFeatureEnabled::enabled('reviews')),
            'timezone' => $timezone,
        ]);
    }
}
