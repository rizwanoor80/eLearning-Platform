<?php

namespace App\Http\Controllers\Tutor;

use App\Enums\LessonStatus;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TutorDashboardController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        /** @var TutorProfile|null $tutorProfile */
        $tutorProfile = $user->tutorProfile;

        // A profile row is created lazily on first visit to onboarding (see
        // TutorOnboardingController::profileFor()); a tutor who has never
        // been there has certainly never had a bookable lesson, so there is
        // nothing to query and nothing to lazily create from a GET request.
        if ($tutorProfile === null) {
            return Inertia::render('tutor/Dashboard', ['today' => [], 'upcoming' => []]);
        }

        $localStartOfToday = CarbonImmutable::now()->setTimezone($user->timezone)->startOfDay();
        $localStartOfTomorrow = $localStartOfToday->addDay();
        $todayStartsUtc = $localStartOfToday->utc();
        $todayEndsUtc = $localStartOfTomorrow->utc();

        $today = Lesson::query()
            ->where('tutor_profile_id', $tutorProfile->id)
            ->whereIn('status', [LessonStatus::Reserved, LessonStatus::Confirmed, LessonStatus::InProgress])
            ->where('starts_at', '>=', $todayStartsUtc)
            ->where('starts_at', '<', $todayEndsUtc)
            ->with(['learner'])
            ->orderBy('starts_at')
            ->get();

        $upcoming = Lesson::query()
            ->where('tutor_profile_id', $tutorProfile->id)
            ->whereIn('status', [LessonStatus::Reserved, LessonStatus::Confirmed])
            ->where('starts_at', '>=', $todayEndsUtc)
            ->with(['learner'])
            ->orderBy('starts_at')
            ->get();

        return Inertia::render('tutor/Dashboard', [
            'today' => $this->present($today, $user),
            'upcoming' => $this->present($upcoming, $user),
        ]);
    }

    /**
     * @param  Collection<int, Lesson>  $lessons
     * @return list<array<string, mixed>>
     */
    private function present(Collection $lessons, User $user): array
    {
        $presented = [];

        foreach ($lessons as $lesson) {
            $presented[] = [
                'id' => $lesson->id,
                'starts_at' => $lesson->starts_at->setTimezone($user->timezone)->format('D, j M Y, g:i A'),
                'duration_minutes' => $lesson->duration_minutes,
                'status' => $lesson->status->value,
                'learner_display_name' => $lesson->learner->display_name,
            ];
        }

        return $presented;
    }
}
