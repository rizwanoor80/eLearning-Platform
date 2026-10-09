<?php

namespace App\Http\Controllers\Tutor;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\TutorProfileStatus;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Scheduling\BookingLeadTime;
use App\Services\Tutors\TutorOnboardingChecklist;
use App\Services\Tutors\TutorSubmissionReadiness;
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
        // R173(a): "no row yet" still needs the banner (same fix as
        // HandleInertiaRequests's needs_onboarding — a tutor who registered and
        // never opened onboarding has not submitted anything either).
        if ($tutorProfile === null) {
            return Inertia::render('tutor/Dashboard', [
                'reportsDue' => [],
                'today' => [],
                'upcoming' => [],
                'slots' => [],
                'onboarding' => $this->onboardingBanner($user, null),
                'needsAvailability' => false,
                'checklist' => $this->checklist($user, null),
                'leadTime' => null,
            ]);
        }

        $localStartOfToday = CarbonImmutable::now()->setTimezone($user->timezone)->startOfDay();
        $localStartOfTomorrow = $localStartOfToday->addDay();
        $todayStartsUtc = $localStartOfToday->utc();
        $todayEndsUtc = $localStartOfTomorrow->utc();

        $today = Lesson::query()
            ->where('tutor_profile_id', $tutorProfile->id)
            ->whereNotIn('status', $this->notOnTodaysList())
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
            'onboarding' => $this->onboardingBanner($user, $tutorProfile),
            'needsAvailability' => $this->needsAvailability($tutorProfile),
            'checklist' => $this->checklist($user, $tutorProfile),
            'leadTime' => $this->leadTime($tutorProfile),
        ]);
    }

    /**
     * R186: the condensed checklist — only the two required groups, and only until both are done.
     * A rejected or suspended tutor has nothing to complete, so none is shown.
     *
     * @return list<array<string, mixed>>|null
     */
    private function checklist(User $user, ?TutorProfile $tutorProfile): ?array
    {
        if ($tutorProfile !== null && in_array($tutorProfile->status, [TutorProfileStatus::Rejected, TutorProfileStatus::Suspended], true)) {
            return null;
        }

        $groups = array_values(array_filter(
            app(TutorOnboardingChecklist::class)->groups($user, $tutorProfile ?? new TutorProfile),
            fn (array $group): bool => $group['required'],
        ));

        return collect($groups)->every(fn (array $group): bool => $group['complete']) ? null : $groups;
    }

    /**
     * R187(c): "Today" is every lesson that starts on the tutor's local day and still stands — including
     * one that has already been taught (completed, reported, settled, disputed, a no-show). It used to list
     * only reserved / confirmed / in-progress, so a 14:00 lesson vanished from the list the moment it ended
     * and the page said "No lessons today". Only a lesson that never became real (unpaid) or that was
     * cancelled, expired or refunded is left off.
     *
     * @return list<LessonStatus>
     */
    private function notOnTodaysList(): array
    {
        return [LessonStatus::PendingPayment, ...LessonStatus::freeingSlot()];
    }

    /**
     * R179: an approved tutor changes their booking lead time here, since the onboarding wizard is
     * locked once submitted. `current` is the effective value, so the select never shows a choice
     * the platform would not honour.
     *
     * @return array{current: int, options: list<array{value: int, label: string}>}|null
     */
    private function leadTime(TutorProfile $tutorProfile): ?array
    {
        if ($tutorProfile->status !== TutorProfileStatus::Approved) {
            return null;
        }

        $leadTime = app(BookingLeadTime::class);

        return [
            'current' => $leadTime->for($tutorProfile),
            'options' => array_map(
                fn (int $hours): array => ['value' => $hours, 'label' => $leadTime->label($hours)],
                $leadTime->options(),
            ),
        ];
    }

    /**
     * R185: an approved tutor with no weekly window is not listed or bookable (`TutorProfile::bookable()`),
     * so the dashboard says so and links to the availability step. Any other status has its own banner or none.
     */
    private function needsAvailability(TutorProfile $tutorProfile): bool
    {
        return $tutorProfile->status === TutorProfileStatus::Approved
            && $tutorProfile->availabilityRules()->doesntExist();
    }

    /**
     * R173(a): the dashboard banner shown while a tutor's submission is still incomplete — same
     * link (`/tutor/onboarding`) and the same "what's missing" list `TutorSubmissionReadiness`
     * already derives for the onboarding wizard's own picker, never a second definition of what
     * counts as missing.
     *
     * @return array{visible: bool, missing: list<string>}
     */
    private function onboardingBanner(User $user, ?TutorProfile $tutorProfile): array
    {
        if ($tutorProfile !== null && ! in_array($tutorProfile->status, [TutorProfileStatus::Draft, TutorProfileStatus::ChangesRequested], true)) {
            return ['visible' => false, 'missing' => []];
        }

        return [
            'visible' => true,
            'missing' => (new TutorSubmissionReadiness)->missing($user, $tutorProfile ?? new TutorProfile),
        ];
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
