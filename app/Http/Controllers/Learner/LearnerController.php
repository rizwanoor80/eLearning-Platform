<?php

namespace App\Http\Controllers\Learner;

use App\Actions\Learner\CreateLearner;
use App\Actions\Learner\DeleteLearner;
use App\Actions\Learner\UpdateLearner;
use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\RecurringSlotStatus;
use App\Exceptions\LearnerDeletionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Learner\StoreLearnerRequest;
use App\Http\Requests\Learner\UpdateLearnerRequest;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Models\User;
use App\Support\YearGroups\YearGroupOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LearnerController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Learner::class);

        return Inertia::render('learners/Index', [
            'learners' => Learner::query()
                ->where('account_user_id', $request->user()->id)
                ->with(['curriculum:id,name', 'yearGroup:id,label'])
                ->orderBy('is_minor')
                ->orderBy('display_name')
                ->get()
                ->map(fn (Learner $learner): array => $this->present($learner))
                ->all(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Learner::class);

        return Inertia::render('learners/Form', [
            'learner' => null,
            'curricula' => $this->curricula(),
            'yearGroups' => YearGroupOptions::all(),
        ]);
    }

    public function store(StoreLearnerRequest $request, CreateLearner $create): RedirectResponse
    {
        /** @var array{display_name: string, year_group_id: int, curriculum_id: int, school?: string|null, notes?: string|null} $data */
        $data = $request->validated();
        $create($request->user(), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Learner added.')]);

        return to_route('learners.index');
    }

    /**
     * The learner's page: their weekly slots, the upcoming weekly lessons (with Skip on the
     * `reserved` ones) and the tutors a new slot can be set up with — those whose trial lesson
     * with this learner is complete (R96).
     */
    public function show(Request $request, Learner $learner): Response
    {
        Gate::authorize('view', $learner);

        /** @var User $user */
        $user = $request->user();

        $slots = $learner->recurringSlots()
            ->with(['tutorProfile.user', 'subject:id,name'])
            ->orderByRaw("status = 'ended'")
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();

        $lessons = Lesson::query()
            ->where('learner_id', $learner->id)
            ->whereNotNull('recurring_slot_id')
            ->whereIn('status', [LessonStatus::Reserved, LessonStatus::Confirmed])
            ->where('starts_at', '>', now())
            ->with(['tutorProfile.user', 'subject:id,name'])
            ->orderBy('starts_at')
            ->get();

        $liveTutorIds = $slots->filter(fn (RecurringSlot $slot): bool => $slot->status !== RecurringSlotStatus::Ended)->pluck('tutor_profile_id')->all();

        $eligible = TutorProfile::query()
            ->bookable()
            ->whereIn('id', Lesson::query()
                ->where('learner_id', $learner->id)
                ->where('type', LessonType::Trial)
                ->whereIn('status', [LessonStatus::Completed, LessonStatus::CompletedReported, LessonStatus::Settled])
                ->select('tutor_profile_id'))
            ->with('user:id,name')
            ->get()
            ->reject(fn (TutorProfile $tutor): bool => in_array($tutor->id, $liveTutorIds, true))
            ->map(fn (TutorProfile $tutor): array => ['id' => $tutor->id, 'name' => $tutor->displayName()])
            ->values()
            ->all();

        return Inertia::render('learners/Show', [
            'learner' => $this->present($learner),
            'slots' => $slots->map(fn (RecurringSlot $slot): array => [
                'id' => $slot->id,
                'status' => $slot->status->value,
                'paused_reason' => $slot->paused_reason?->value,
                'tutor' => $slot->tutorProfile->displayName(),
                'subject' => $slot->subject?->name,
                'schedule' => $slot->scheduleLabel(),
                'next' => $slot->status === RecurringSlotStatus::Ended
                    ? null
                    : $slot->nextOccurrenceAfter(now())?->setTimezone($user->timezone)->format('D, j M Y, g:i A'),
                'price' => $slot->price->format(),
                'ends_on' => $slot->ends_on?->format('j M Y'),
                'end_effective_on' => $slot->end_effective_on?->format('j M Y'),
                'ended_by_tutor' => $slot->end_effective_on !== null && $slot->ended_by_user_id !== null,
            ])->all(),
            'lessons' => $lessons->map(fn (Lesson $lesson): array => [
                'id' => $lesson->id,
                'starts_at' => $lesson->starts_at->setTimezone($user->timezone)->format('D, j M Y, g:i A'),
                'status' => $lesson->status->value,
                'subject' => $lesson->subject?->name,
                'tutor' => $lesson->tutorProfile->displayName(),
                'cancel_kind' => $lesson->status === LessonStatus::Reserved ? 'skip' : null,
            ])->all(),
            'eligible_tutors' => $eligible,
            'timezone' => $user->timezone,
        ]);
    }

    public function edit(Learner $learner): Response
    {
        Gate::authorize('update', $learner);

        return Inertia::render('learners/Form', [
            'learner' => $this->present($learner),
            'curricula' => $this->curricula(),
            'yearGroups' => YearGroupOptions::all(),
        ]);
    }

    public function update(UpdateLearnerRequest $request, Learner $learner, UpdateLearner $update): RedirectResponse
    {
        $update($learner, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Learner updated.')]);

        return to_route('learners.index');
    }

    public function destroy(Request $request, Learner $learner, DeleteLearner $delete): RedirectResponse
    {
        Gate::authorize('delete', $learner);

        try {
            $delete($request->user(), $learner);
        } catch (LearnerDeletionException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('learners.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Learner removed.')]);

        return to_route('learners.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Learner $learner): array
    {
        return [
            'id' => $learner->id,
            'display_name' => $learner->display_name,
            'is_minor' => $learner->is_minor,
            'year_group_id' => $learner->year_group_id,
            'year_group' => $learner->yearGroupLabel(),
            'year_group_is_legacy' => $learner->year_group_id === null && $learner->year_group_legacy !== null,
            'curriculum_id' => $learner->curriculum_id,
            'curriculum' => $learner->curriculum?->name,
            'school' => $learner->school,
            'notes' => $learner->notes,
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function curricula(): array
    {
        $curricula = [];

        foreach (Curriculum::query()->orderBy('sort')->get(['id', 'name']) as $curriculum) {
            $curricula[] = ['id' => $curriculum->id, 'name' => $curriculum->name];
        }

        return $curricula;
    }
}
