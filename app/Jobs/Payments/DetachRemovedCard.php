<?php

namespace App\Jobs\Payments;

use App\Models\PaymentMethod;
use App\Services\Payments\NoActivePaymentGateway;
use App\Support\Payments\ActiveStripe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * 24c (R261): detaches a removed Stripe card from its customer. The removal itself is recorded first
 * (`RemoveSavedCard`), so the platform never charges the card again whatever happens here; this only makes the
 * gateway forget it, and is retried if the gateway is down. A card Stripe says is already gone counts as done,
 * so running twice is a no-op.
 */
class DetachRemovedCard implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(public readonly int $paymentMethodId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(ActiveStripe $stripe): void
    {
        $card = PaymentMethod::query()->find($this->paymentMethodId);

        if ($card === null || $card->removed_at === null || $card->gateway !== 'stripe') {
            return;
        }

        try {
            $stripe->client()->paymentMethods->detach($card->gateway_token);
        } catch (ApiErrorException $e) {
            if ($e->getStripeCode() === 'resource_missing' || str_contains(strtolower($e->getMessage()), 'not attached')) {
                return;
            }

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        // The row is already removed, so nothing can charge it; only the gateway still holds the card.
        Log::error('A removed Stripe card could not be detached at the gateway.', [
            'payment_method_id' => $this->paymentMethodId,
            'error' => $e::class,
            'unconfigured' => $e instanceof NoActivePaymentGateway,
        ]);
    }
}
