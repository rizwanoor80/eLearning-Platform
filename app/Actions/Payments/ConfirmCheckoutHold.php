<?php

namespace App\Actions\Payments;

use App\Enums\PaymentGatewayMode;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Payments\StripePaymentGateway;
use App\Support\Lessons\LessonQuote;
use App\Support\Payments\ActiveStripe;
use Throwable;

/**
 * R269: before the booking page tells a parent "your card was approved", the server confirms that a hold
 * really exists. It reads the PaymentIntent the browser names and answers yes only when it is held
 * (`requires_capture`, manual capture) in this row's mode, was made by the checkout for this account and
 * this tutor and start time, is for exactly the amount the server would quote now for the learner it was
 * made for, and is not already the reference of a payment. Nothing is written, captured or cancelled here:
 * a crafted link (a stranger's intent id, a spent one, another mode's, the wrong amount) just gets "no".
 *
 * Any failure to ask Stripe is also "no": the page then says the payment could not be confirmed and that
 * nothing was charged, which is true, because the money is only ever taken inside `BookLesson`.
 */
class ConfirmCheckoutHold
{
    public function __construct(
        private readonly ActiveStripe $stripe,
        private readonly LessonQuote $quotes,
    ) {}

    /**
     * @return int|null the id of the learner the hold was made for when it is confirmed, null when it is not
     */
    public function __invoke(User $user, string $paymentIntentId, int $tutorId, string $startsAt): ?int
    {
        $row = $this->stripe->row();

        if ($row === null) {
            return null;
        }

        try {
            $intent = $this->stripe->client()->paymentIntents->retrieve($paymentIntentId);
        } catch (Throwable) {
            return null;
        }

        $meta = $intent->metadata;

        if ((bool) $intent->livemode !== (($row->mode ?? PaymentGatewayMode::Test) === PaymentGatewayMode::Live)
            || $intent->status !== 'requires_capture'
            || $intent->capture_method !== 'manual'
            || strtolower((string) $intent->currency) !== StripePaymentGateway::CURRENCY
            || ($meta['kind'] ?? null) !== StripePaymentGateway::KIND_CHECKOUT
            || (string) ($meta['account_user_id'] ?? '') !== (string) $user->id
            || (string) ($meta['tutor_profile_id'] ?? '') !== (string) $tutorId
            || (string) ($meta['starts_at'] ?? '') !== $startsAt) {
            return null;
        }

        $learner = Learner::query()->withTrashed()->find((int) ($meta['learner_id'] ?? 0));
        $tutor = TutorProfile::query()->bookable()->find($tutorId);

        if ($learner === null || $tutor === null || ! $user->can('bookFor', [Lesson::class, $learner])) {
            return null;
        }

        [, $price] = $this->quotes->decide($learner, $tutor);

        if ($price === null || $price->isZero() || $price->isNegative()
            || $intent->amount !== $price->toFils()
            || $intent->amount_capturable !== $price->toFils()) {
            return null;
        }

        if (Payment::query()->where('gateway', 'stripe')->where('gateway_ref', $intent->id)->exists()) {
            return null;
        }

        return $learner->id;
    }
}
