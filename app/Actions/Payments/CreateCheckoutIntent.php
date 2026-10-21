<?php

namespace App\Actions\Payments;

use App\Exceptions\BookingException;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Payments\NoActivePaymentGateway;
use App\Services\Payments\StripePaymentGateway;
use App\Services\Scheduling\Slot;
use App\Services\Scheduling\SlotCalculator;
use App\Services\Tutors\TutorRateBands;
use App\Support\Lessons\LessonQuote;
use App\Support\Payments\ActiveStripe;
use Carbon\CarbonImmutable;

/**
 * R248: the PaymentIntent the parent's browser confirms before `BookLesson` runs. Everything about it is
 * decided here, on the server: the amount is the quoted price in fils (never a client number), the
 * currency is AED, cards only, and `capture_method: manual` so the browser's confirmation (3-D Secure
 * included) only holds the money — `StripePaymentGateway::capture()` takes it, inside `BookLesson`,
 * after every booking rule has passed, and `CancelCheckoutIntent` releases it when the booking fails.
 *
 * The metadata binds the hold to this booking (account, learner, tutor, start time) and carries no
 * name, email or other personal data. The same checks as the booking form run first, so a parent who is
 * not allowed to book, or whose quote or slot has gone stale, is never asked for a card.
 *
 * R259 (3): the browser names each payment attempt (`attempt_id`, a uuid it keeps until the attempt ends
 * in a booking or a failure). The idempotency key is made from that id and everything the hold is for, so
 * a double click or a repeated submit gets the same hold back from Stripe and never a second one; a
 * changed price or slot is a new key. An abandoned hold lapses by itself or is cancelled.
 */
class CreateCheckoutIntent
{
    public function __construct(
        private readonly ActiveStripe $stripe,
        private readonly LessonQuote $quotes,
        private readonly SlotCalculator $slots,
        private readonly TutorRateBands $bands,
    ) {}

    /**
     * @param  array{learner_id: int, tutor_id: int, starts_at: CarbonImmutable, quote_token: string, attempt_id?: string|null}  $data
     * @return array{id: string, client_secret: string}
     *
     * @throws BookingException the booking cannot be paid for right now; the message is safe to show
     * @throws NoActivePaymentGateway
     */
    public function __invoke(User $user, array $data): array
    {
        $learner = Learner::query()->withTrashed()->find($data['learner_id']);
        $tutor = TutorProfile::query()->bookable()->find($data['tutor_id']);

        if ($learner === null || $tutor === null || ! $user->can('bookFor', [Lesson::class, $learner])) {
            throw new BookingException(__('That lesson cannot be booked.'));
        }

        if (! $this->quotes->isCurrent($learner, $tutor, $data['quote_token'])) {
            throw new BookingException(__('The type or price of this lesson has changed. Please review it and confirm again.'));
        }

        if ($this->bands->problemWithRate($tutor) !== null) {
            throw new BookingException(__('That lesson cannot be booked.'));
        }

        $startsAt = $data['starts_at'];
        $open = collect($this->slots->forTutor($tutor, 'UTC'))->contains(
            fn (Slot $slot): bool => $slot->startsAt->getTimestamp() === $startsAt->getTimestamp()
        );

        if (! $open) {
            throw new BookingException(__('The requested slot is no longer available.'));
        }

        [, $price] = $this->quotes->decide($learner, $tutor);

        if ($price === null || $price->isZero() || $price->isNegative()) {
            throw new BookingException(__('That lesson cannot be booked.'));
        }

        $options = isset($data['attempt_id']) && $data['attempt_id'] !== ''
            ? ['idempotency_key' => 'checkout-'.hash('sha256', implode('|', [$user->id, $data['attempt_id'], $learner->id, $tutor->id, $startsAt->utc()->format(StripePaymentGateway::STARTS_AT_FORMAT), $price->toFils()]))]
            : [];

        $intent = $this->stripe->client()->paymentIntents->create([
            'amount' => $price->toFils(),
            'currency' => StripePaymentGateway::CURRENCY,
            'capture_method' => 'manual',
            'payment_method_types' => ['card'],
            'metadata' => [
                'kind' => StripePaymentGateway::KIND_CHECKOUT,
                'account_user_id' => (string) $user->id,
                'learner_id' => (string) $learner->id,
                'tutor_profile_id' => (string) $tutor->id,
                'starts_at' => $startsAt->utc()->format(StripePaymentGateway::STARTS_AT_FORMAT),
            ],
        ], $options);

        return ['id' => $intent->id, 'client_secret' => (string) $intent->client_secret];
    }
}
