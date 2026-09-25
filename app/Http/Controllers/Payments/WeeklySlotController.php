<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\SaveTestCard;
use App\Actions\RecurringSlots\CreateRecurringSlot;
use App\Actions\RecurringSlots\EndRecurringSlot;
use App\Enums\LessonStatus;
use App\Enums\RecurringSlotStatus;
use App\Exceptions\RecurringSlotException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StoreWeeklySlotRequest;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PaymentMethod;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Scheduling\WeeklySlotOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The parent's weekly-slot screens: set one up with a tutor (R96, then the fake add-card step
 * R100 when no card is saved) and end one (R99). All rules live in the actions; this only shapes
 * the pages and turns a `RecurringSlotException` into a form error.
 */
class WeeklySlotController extends Controller
{
    public function create(Request $request, CreateRecurringSlot $create, WeeklySlotOptions $options): Response
    {
        /** @var User $user */
        $user = $request->user();

        $tutor = TutorProfile::query()
            ->bookable()
            ->with(['user:id,name', 'tutorSubjects.curriculum:id,name', 'tutorSubjects.subject:id,name'])
            ->findOrFail($request->integer('tutor'));

        $learners = Learner::query()
            ->where('account_user_id', $user->id)
            ->orderBy('is_minor')
            ->orderBy('display_name')
            ->get();

        $requested = $request->integer('learner');
        $selected = $learners->contains('id', $requested) ? $requested : ($learners->count() === 1 ? $learners->first()->id : null);

        $card = PaymentMethod::query()->where('account_user_id', $user->id)->first();
        $usableCard = $card !== null && $card->isUsable();

        return Inertia::render('weekly-slots/Create', [
            'tutor' => [
                'id' => $tutor->id,
                'name' => $tutor->displayName(),
                'rate' => $tutor->hourly_rate?->format(),
            ],
            'learners' => $learners->map(fn (Learner $learner): array => [
                'id' => $learner->id,
                'display_name' => $learner->display_name,
                'trial_completed' => $create->hasCompletedTrial($learner, $tutor),
            ])->all(),
            'selected_learner' => $selected,
            'subjects' => $tutor->tutorSubjects->map(fn ($row): array => [
                'curriculum_id' => $row->curriculum_id,
                'subject_id' => $row->subject_id,
                'label' => $row->subject?->name.' · '.$row->curriculum?->name,
            ])->values()->all(),
            'options' => $options->forTutor($tutor, $user->timezone),
            'timezone' => $user->timezone,
            'card' => $usableCard ? ['brand' => $card->brand, 'last4' => $card->last4] : null,
            'can_add_card' => SaveTestCard::available(),
            'min_start' => now()->setTimezone($user->timezone)->toDateString(),
        ]);
    }

    public function store(StoreWeeklySlotRequest $request, CreateRecurringSlot $create): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{learner_id: int, tutor_id: int, curriculum_id: int, subject_id: int, weekday: int, start_time: string, starts_on: string, ends_on?: string|null} $data */
        $data = $request->validated();

        // Ownership is the action's job (`createFor`); a learner that is not the caller's is refused
        // exactly like any other rejected setup, without saying whether the id exists.
        $learner = Learner::query()->withTrashed()->find($data['learner_id']);
        $tutor = TutorProfile::query()->bookable()->find($data['tutor_id']);

        if ($learner === null || $tutor === null) {
            throw ValidationException::withMessages(['slot' => __('That weekly slot cannot be set up.')]);
        }

        try {
            $create($user, $learner, $tutor, [
                'curriculum_id' => $data['curriculum_id'],
                'subject_id' => $data['subject_id'],
                'weekday' => $data['weekday'],
                'start_time' => $data['start_time'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null,
            ]);
        } catch (RecurringSlotException $e) {
            throw ValidationException::withMessages(['slot' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Weekly slot set up.')]);

        return to_route('learners.show', $learner);
    }

    public function endShow(Request $request, RecurringSlot $slot): Response
    {
        Gate::authorize('end', $slot);

        $slot->loadMissing(['learner', 'tutorProfile', 'subject:id,name']);

        $future = Lesson::query()
            ->where('recurring_slot_id', $slot->id)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at');

        $reserved = (clone $future)->where('status', LessonStatus::Reserved)->count();
        $confirmed = (clone $future)->where('status', LessonStatus::Confirmed)->get();

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('weekly-slots/End', [
            'weekly_slot' => [
                'id' => $slot->id,
                'ended' => $slot->status === RecurringSlotStatus::Ended,
                'learner' => $slot->learner->display_name,
                'tutor' => $slot->tutorProfile->displayName(),
                'subject' => $slot->subject?->name,
                'schedule' => $slot->scheduleLabel(),
            ],
            'reserved_count' => $reserved,
            'confirmed' => $confirmed->map(fn (Lesson $lesson): array => [
                'id' => $lesson->id,
                'starts_at' => $lesson->starts_at->setTimezone($user->timezone)->format('D, j M Y, g:i A'),
                'cancel_window_hours' => $lesson->cancel_window_hours,
            ])->all(),
        ]);
    }

    public function end(Request $request, RecurringSlot $slot, EndRecurringSlot $end): RedirectResponse
    {
        Gate::authorize('end', $slot);

        /** @var User $user */
        $user = $request->user();

        try {
            $end($user, $slot);
        } catch (RecurringSlotException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return $this->backToLearner($slot);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Weekly slot ended.')]);

        return $this->backToLearner($slot);
    }

    /**
     * A removed learner has no page to return to (`DeleteLearner` refuses while a slot is live, but
     * an anonymised account can still reach here), so fall back to the dashboard.
     */
    private function backToLearner(RecurringSlot $slot): RedirectResponse
    {
        return $slot->learner->trashed() ? to_route('dashboard') : to_route('learners.show', $slot->learner_id);
    }
}
