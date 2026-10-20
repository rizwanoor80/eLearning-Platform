<?php

namespace App\Actions\Payments;

use App\Models\User;
use App\Services\Payments\StripePaymentGateway;
use App\Support\Payments\ActiveStripe;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Throwable;

/**
 * R248: releases the hold a checkout made when the booking behind it did not happen (a taken slot, a
 * stale quote, a declined capture). Cancelling an authorisation that was never captured gives the money
 * straight back with no refund and no ledger entry, so it is safe to try on any failure and never
 * changes what the parent was charged. Quiet by design: it only ever cancels an intent this account's
 * own checkout made, and an intent that is already captured (the booking won a race) or already gone
 * simply refuses and nothing happens. A hold we could not release lapses by itself at Stripe.
 */
class CancelCheckoutIntent
{
    private const CANCELLABLE = [
        PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
        PaymentIntent::STATUS_REQUIRES_CONFIRMATION,
        PaymentIntent::STATUS_REQUIRES_ACTION,
        PaymentIntent::STATUS_REQUIRES_CAPTURE,
    ];

    public function __construct(private readonly ActiveStripe $stripe) {}

    public function __invoke(User $user, string $intentId): void
    {
        try {
            $client = $this->stripe->client();
            $intent = $client->paymentIntents->retrieve($intentId);

            if (($intent->metadata['kind'] ?? null) !== StripePaymentGateway::KIND_CHECKOUT
                || (string) ($intent->metadata['account_user_id'] ?? '') !== (string) $user->id
                || ! in_array($intent->status, self::CANCELLABLE, true)) {
                return;
            }

            $client->paymentIntents->cancel($intent->id, ['cancellation_reason' => 'abandoned']);
        } catch (ApiErrorException $e) {
            Log::warning('A checkout hold could not be released.', ['payment_intent' => $intentId, 'stripe_error' => $e->getStripeCode()]);
        } catch (Throwable $e) {
            Log::warning('A checkout hold could not be released.', ['payment_intent' => $intentId, 'exception' => $e::class]);
        }
    }
}
