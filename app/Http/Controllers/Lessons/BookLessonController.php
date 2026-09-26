<?php

namespace App\Http\Controllers\Lessons;

use App\Actions\Lessons\BookLesson;
use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Exceptions\BookingException;
use App\Exceptions\PaymentCaptureException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lessons\StoreLessonBookingRequest;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Scheduling\Slot;
use App\Services\Scheduling\SlotCalculator;
use App\Support\Facades\Settings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The parent's single-booking screen (R114): the slot chosen on a tutor's profile, the learner and
 * subject pickers, and "Confirm and pay". Every rule lives in `BookLesson`, which is called unchanged;
 * this only shapes the page and turns its exceptions into a form error. The page shows what the
 * action will decide (trial or regular, and the price) by asking the same question of the same
 * data, per learner, on the server — the browser never computes or sends either.
 */
class BookLessonController extends Controller
{
    public function create(Request $request, SlotCalculator $calculator): RedirectResponse|Response
    {
        /** @var User $user */
        $user = $request->user();

        $tutor = TutorProfile::query()
            ->bookable()
            ->with(['user:id,name,timezone', 'tutorSubjects.curriculum:id,name', 'tutorSubjects.subject:id,name'])
            ->findOrFail((int) $request->route('tutor'));

        $startsAt = $this->parseStartsAt($request->query('starts_at'));

        if ($startsAt === null) {
            return to_route('tutors.show', $tutor->id);
        }

        $slots = $calculator->forTutor($tutor, $user->timezone);
        $available = collect($slots)->contains(
            fn (Slot $slot): bool => $slot->startsAt->getTimestamp() === $startsAt->getTimestamp()
        );

        $learners = Learner::query()
            ->where('account_user_id', $user->id)
            ->orderBy('is_minor')
            ->orderBy('display_name')
            ->get();

        $subjects = $tutor->tutorSubjects->map(fn ($row): array => [
            'curriculum_id' => $row->curriculum_id,
            'subject_id' => $row->subject_id,
            'label' => $row->subject?->name.' · '.$row->curriculum?->name,
        ])->values()->all();

        return Inertia::render('lessons/Book', [
            'tutor' => ['id' => $tutor->id, 'name' => $tutor->displayName()],
            'learners' => $learners->map(fn (Learner $learner): array => [
                'id' => $learner->id,
                'display_name' => $learner->display_name,
                ...$this->quote($learner, $tutor),
                // The subject row to preselect: the learner's own curriculum, when this tutor teaches it.
                'preselect' => collect($subjects)->first(fn (array $row): bool => $row['curriculum_id'] === $learner->curriculum_id),
            ])->all(),
            'selected_learner' => $learners->count() === 1 ? $learners->first()->id : null,
            'subjects' => $subjects,
            'starts_at' => $startsAt->format(StoreLessonBookingRequest::STARTS_AT_FORMAT),
            'slot_label' => $startsAt->setTimezone($user->timezone)->format('D j M Y, H:i'),
            'slot_available' => $available,
            'alternatives' => array_map(fn (Slot $slot): array => [
                'starts_at' => $slot->startsAt->utc()->format(StoreLessonBookingRequest::STARTS_AT_FORMAT),
                'label' => $slot->startsAt->format('D j M, H:i'),
            ], array_slice($slots, 0, 12)),
            'timezone' => $user->timezone,
            'can_pay' => app()->bound(PaymentGateway::class),
        ]);
    }

    public function store(StoreLessonBookingRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{learner_id: int, tutor_id: int, curriculum_id: int, subject_id: int, starts_at: string, quote_token: string} $data */
        $data = $request->validated();

        // A learner that is not the caller's, and a tutor that is not bookable (or does not exist), are
        // refused with one generic message, checked before the action so nothing later says whether the
        // id exists. `BookLesson` still re-applies both rules.
        $learner = Learner::query()->withTrashed()->find($data['learner_id']);
        $tutor = TutorProfile::query()->bookable()->find($data['tutor_id']);

        if ($learner === null || $tutor === null || ! $user->can('bookFor', [Lesson::class, $learner])) {
            throw ValidationException::withMessages(['slot' => __('That lesson cannot be booked.')]);
        }

        // Production has no gateway bound until CP5 (ADR-016): say so plainly instead of failing with a 500.
        if (! app()->bound(PaymentGateway::class)) {
            throw ValidationException::withMessages(['slot' => __('Booking is not available yet.')]);
        }

        $startsAt = $this->parseStartsAt($data['starts_at']);

        if ($startsAt === null) {
            throw ValidationException::withMessages(['starts_at' => __('That time is not valid.')]);
        }

        // What the parent confirmed is what the server would charge right now: the token was made from
        // the type and price shown. Someone who booked the trial in another tab, or a rate or discount
        // change since the page was drawn, makes it stale; refuse and let the page show the new quote.
        if (! hash_equals($this->quoteToken($learner, $tutor), $data['quote_token'])) {
            throw ValidationException::withMessages(['slot' => __('The type or price of this lesson has changed. Please review it and confirm again.')]);
        }

        try {
            app(BookLesson::class)($user, $learner, $tutor, [
                'curriculum_id' => $data['curriculum_id'],
                'subject_id' => $data['subject_id'],
                'starts_at' => $startsAt,
            ]);
        } catch (BookingException $e) {
            throw ValidationException::withMessages(['slot' => $e->getMessage()]);
        } catch (PaymentCaptureException) {
            throw ValidationException::withMessages(['slot' => __('Payment did not go through. Nothing was booked.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Lesson confirmed.')]);

        return to_route('learners.show', $learner);
    }

    /**
     * What `BookLesson` will decide for this learner and tutor, in the same terms and from the same
     * data: the first lesson of the pair that is not in a slot-freeing status is the trial, at
     * `trialPrice()`, otherwise a regular lesson at the hourly rate. Display only — the action
     * decides again at booking time and freezes the price it computes.
     *
     * @return array{type: string, type_label: string, price: string|null, quote_token: string}
     */
    private function quote(Learner $learner, TutorProfile $tutor): array
    {
        [$isTrial, $price] = $this->decide($learner, $tutor);

        return [
            'type' => $isTrial ? LessonType::Trial->value : LessonType::Regular->value,
            'type_label' => $isTrial ? __('Trial lesson') : __('Lesson'),
            'price' => $price?->format((string) Settings::get('currency_code')),
            'quote_token' => $this->quoteToken($learner, $tutor),
        ];
    }

    /**
     * @return array{0: bool, 1: Money|null} whether the next lesson would be the trial, and its price
     */
    private function decide(Learner $learner, TutorProfile $tutor): array
    {
        $isTrial = ! Lesson::query()
            ->where('learner_id', $learner->id)
            ->where('tutor_profile_id', $tutor->id)
            ->whereNotIn('status', LessonStatus::freeingSlotValues())
            ->exists();

        return [$isTrial, $isTrial ? $tutor->trialPrice() : $tutor->hourly_rate];
    }

    /**
     * An opaque, keyed fingerprint of the quote (learner, tutor, type, price in fils, currency). The page
     * carries it and the form returns it; the browser can neither read a type or price out of it nor
     * choose one — a wrong or stale token is only ever refused.
     */
    private function quoteToken(Learner $learner, TutorProfile $tutor): string
    {
        [$isTrial, $price] = $this->decide($learner, $tutor);

        return hash_hmac('sha256', implode('|', [
            $learner->id,
            $tutor->id,
            $isTrial ? LessonType::Trial->value : LessonType::Regular->value,
            $price?->toFils() ?? 'none',
            (string) Settings::get('currency_code'),
        ]), (string) config('app.key'));
    }

    private function parseStartsAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!'.StoreLessonBookingRequest::STARTS_AT_FORMAT, $value, 'UTC');
        } catch (InvalidFormatException) {
            return null;
        }

        // createFromFormat is lenient (month 13 rolls over): only a value that formats back to itself is real.
        if ($parsed->format(StoreLessonBookingRequest::STARTS_AT_FORMAT) !== $value) {
            return null;
        }

        return $parsed;
    }
}
