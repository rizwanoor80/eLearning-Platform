<?php

namespace App\Http\Controllers\Lessons;

use App\Actions\Lessons\BookLesson;
use App\Actions\Payments\CancelCheckoutIntent;
use App\Exceptions\BookingException;
use App\Exceptions\PaymentCaptureException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lessons\StoreLessonBookingRequest;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Payments\NoActivePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Scheduling\Slot;
use App\Services\Scheduling\SlotCalculator;
use App\Support\Facades\Settings;
use App\Support\Lessons\LessonQuote;
use App\Support\Payments\ActiveStripe;
use App\Support\Payments\CheckoutIntent;
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
    public function create(Request $request, SlotCalculator $calculator, PaymentGatewayManager $gateways, LessonQuote $quotes, ActiveStripe $stripe): RedirectResponse|Response
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

        // No active gateway: say so before any form, so nothing here can lead to a `payments` row. The
        // parent sees a plain notice and the admin sees the matching one on the Payment gateways list.
        if (! $gateways->available() || ($stripe->isActive() && $stripe->publishableKey() === null)) {
            return Inertia::render('lessons/BookingUnavailable', ['tutor' => ['id' => $tutor->id, 'name' => $tutor->displayName()]]);
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
                ...$quotes->describe($learner, $tutor),
                // The subject row to preselect: the learner's own curriculum, when this tutor teaches it.
                'preselect' => collect($subjects)->first(fn (array $row): bool => $row['curriculum_id'] === $learner->curriculum_id),
            ])->all(),
            'selected_learner' => $learners->count() === 1 ? $learners->first()->id : null,
            'subjects' => $subjects,
            'starts_at' => $startsAt->format(StoreLessonBookingRequest::STARTS_AT_FORMAT),
            'slot_label' => $startsAt->setTimezone($user->timezone)->format('D j M Y, H:i'),
            'slot_available' => $available,
            'cancel_notice' => $this->cancelNotice($startsAt),
            'alternatives' => array_map(fn (Slot $slot): array => [
                'starts_at' => $slot->startsAt->utc()->format(StoreLessonBookingRequest::STARTS_AT_FORMAT),
                'label' => $slot->startsAt->format('D j M, H:i'),
            ], array_slice($slots, 0, 12)),
            'timezone' => $user->timezone,
            // R248: null for the fake driver, whose booking form posts straight to `lessons.store`.
            'stripe' => $stripe->isActive() ? ['publishable_key' => $stripe->publishableKey()] : null,
        ]);
    }

    public function store(StoreLessonBookingRequest $request, PaymentGatewayManager $gateways, LessonQuote $quotes, ActiveStripe $stripe): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{learner_id: int, tutor_id: int, curriculum_id: int, subject_id: int, starts_at: string, quote_token: string, payment_intent_id?: string|null} $data */
        $data = $request->validated();

        // A learner that is not the caller's, and a tutor that is not bookable (or does not exist), are
        // refused with one generic message, checked before the action so nothing later says whether the
        // id exists. `BookLesson` still re-applies both rules.
        $learner = Learner::query()->withTrashed()->find($data['learner_id']);
        $tutor = TutorProfile::query()->bookable()->find($data['tutor_id']);

        // R248: the browser has already held the card by the time it posts, so every refusal below
        // releases that hold (a no-op when there is none, or when the intent is not this account's).
        $heldIntent = $stripe->isActive() ? ($data['payment_intent_id'] ?? null) : null;

        if ($learner === null || $tutor === null || ! $user->can('bookFor', [Lesson::class, $learner])) {
            $this->releaseHold($user, $heldIntent);

            throw ValidationException::withMessages(['slot' => __('That lesson cannot be booked.')]);
        }

        // No active gateway in the registry (R246): say so plainly instead of failing with a 500.
        if (! $gateways->available()) {
            $this->releaseHold($user, $heldIntent);

            throw ValidationException::withMessages(['slot' => __('Booking is not available yet.')]);
        }

        $startsAt = $this->parseStartsAt($data['starts_at']);

        if ($startsAt === null) {
            $this->releaseHold($user, $heldIntent);

            throw ValidationException::withMessages(['starts_at' => __('That time is not valid.')]);
        }

        // What the parent confirmed is what the server would charge right now: the token was made from
        // the type and price shown. Someone who booked the trial in another tab, or a rate or discount
        // change since the page was drawn, makes it stale; refuse and let the page show the new quote.
        if (! $quotes->isCurrent($learner, $tutor, $data['quote_token'])) {
            $this->releaseHold($user, $heldIntent);

            throw ValidationException::withMessages(['slot' => __('The type or price of this lesson has changed. Please review it and confirm again.')]);
        }

        // R248: with Stripe the card was confirmed in the browser first and only held. Without the id of
        // that hold there is nothing to take, so refuse before any lesson or payment row exists.
        $intentId = null;

        if ($stripe->isActive()) {
            $intentId = $data['payment_intent_id'] ?? null;

            if ($intentId === null) {
                throw ValidationException::withMessages(['slot' => __('Please enter your card details to book this lesson.')]);
            }

            app(CheckoutIntent::class)->set($intentId);
        }

        try {
            app(BookLesson::class)($user, $learner, $tutor, [
                'curriculum_id' => $data['curriculum_id'],
                'subject_id' => $data['subject_id'],
                'starts_at' => $startsAt,
            ]);
        } catch (BookingException $e) {
            $this->releaseHold($user, $intentId);

            // `BookLesson` words this one for the admin who has to chase it, with the lesson's id. The parent
            // is told the truth without it: the money was taken and will be returned.
            throw ValidationException::withMessages(['slot' => str_starts_with($e->getMessage(), 'Payment was captured')
                ? __('Your payment was taken but we could not confirm the lesson. We will refund you; please contact us if you do not see it within a few days.')
                : $e->getMessage()]);
        } catch (NoActivePaymentGateway) {
            // An admin deactivated the gateway between the check above and the action: nothing was written.
            $this->releaseHold($user, $intentId);

            throw ValidationException::withMessages(['slot' => __('Booking is not available yet.')]);
        } catch (PaymentCaptureException) {
            $this->releaseHold($user, $intentId);

            throw ValidationException::withMessages(['slot' => __('Payment did not go through. Nothing was booked.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Lesson confirmed.')]);

        return to_route('learners.show', $learner);
    }

    /** R248: a booking that did not happen must not leave the parent's card held. Quiet and safe to call for any failure. */
    private function releaseHold(User $user, ?string $intentId): void
    {
        if ($intentId !== null) {
            app(CancelCheckoutIntent::class)($user, $intentId);
        }
    }

    /**
     * R179: stated before payment, only when the rule applies. The lesson does not exist yet, so the
     * window is the current `cancel_window_hours` — the value `BookLesson` is about to freeze on it
     * (invariant #11) — and the test is `CancelLesson`'s own, one step ahead: a parent cancelling at
     * any moment after booking is past `starts_at - window` exactly when `starts_at <= now + window`.
     * Nothing about the cancellation or refund rule changes.
     */
    private function cancelNotice(CarbonImmutable $startsAt): ?string
    {
        $hours = (int) Settings::get('cancel_window_hours');

        if ($startsAt->greaterThan(CarbonImmutable::now()->addHours($hours))) {
            return null;
        }

        return trans_choice('This lesson starts within :count hour, so it cannot be cancelled for a refund.|This lesson starts within :count hours, so it cannot be cancelled for a refund.', $hours);
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
