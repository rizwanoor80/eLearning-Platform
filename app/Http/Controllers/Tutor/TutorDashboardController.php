<?php

namespace App\Http\Controllers\Tutor;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
            return Inertia::render('tutor/Dashboard', ['reportsDue' => [], 'today' => [], 'upcoming' => [], 'slots' => []]);
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

        // Lessons waiting for their report (CP6 7e), oldest first: `completed` ones, whose payment is released
        // when it is filed or by the sweep at the frozen `auto_release_at`, and ones the sweep already released
        // (`report_late_at`), which still take the report, late.
        $reportsDue = Lesson::query()
            ->where('tutor_profile_id', $tutorProfile->id)
            ->where(fn (Builder $q) => $q
                ->where('status', LessonStatus::Completed)
                ->orWhere(fn (Builder $late) => $late
                    ->where('status', LessonStatus::CompletedReported)
                    ->whereNotNull('report_late_at')
                    ->whereDoesntHave('progressReport')))
            ->with(['learner'])
            ->orderBy('ends_at')
            ->get();

        return Inertia::render('tutor/Dashboard', [
            'reportsDue' => $this->presentReportsDue($reportsDue, $user),
            'today' => $this->present($today, $user),
            'upcoming' => $this->present($upcoming, $user),
            'slots' => $this->slots($tutorProfile),
        ]);
    }

    /**
     * The tutor's standing weekly slots, shown as fixed blocks (R99): `ending_on` is set once the
     * tutor has given notice, and the slot then still runs through that date.
     *
     * @return list<array<string, mixed>>
     */
    private function slots(TutorProfile $tutorProfile): array
    {
        $slots = RecurringSlot::query()
            ->where('tutor_profile_id', $tutorProfile->id)
            ->whereIn('status', RecurringSlot::holdingStatuses())
            ->with(['learner', 'subject:id,name'])
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get()
            ->map(fn (RecurringSlot $slot): array => [
                'id' => $slot->id,
                'learner_display_name' => $slot->learner->display_name,
                'subject' => $slot->subject?->name,
                'schedule' => $slot->scheduleLabel(),
                'status' => $slot->status->value,
                'ending_on' => $slot->end_effective_on?->format('D, j M Y'),
            ])
            ->all();

        return array_values($slots);
    }

    /**
     * @param  Collection<int, Lesson>  $lessons
     * @return list<array<string, mixed>>
     */
    private function presentReportsDue(Collection $lessons, User $user): array
    {
        $presented = [];

        foreach ($lessons as $lesson) {
            $presented[] = [
                'id' => $lesson->id,
                'starts_at' => $lesson->starts_at->setTimezone($user->timezone)->format('D, j M Y, g:i A'),
                'learner_display_name' => $lesson->learner->display_name,
                'is_trial' => $lesson->type === LessonType::Trial,
                'released' => $lesson->status !== LessonStatus::Completed,
                'due_by' => $lesson->report_due_at?->setTimezone($user->timezone)->format('D, j M Y, g:i A'),
                'auto_release_by' => $lesson->auto_release_at?->setTimezone($user->timezone)->format('D, j M Y, g:i A'),
            ];
        }

        return $presented;
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
                'weekly' => $lesson->recurring_slot_id !== null,
            ];
        }

        return $presented;
    }
}
