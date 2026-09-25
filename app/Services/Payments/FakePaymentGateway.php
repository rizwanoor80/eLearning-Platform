<?php

namespace App\Services\Payments;

use App\Enums\TestCard;
use App\Exceptions\PaymentCaptureException;
use App\Models\Lesson;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * The only driver until CP5 adds a real one. Captures instantly and always
 * succeeds — bound only from the test side (see the PaymentGateway
 * interface's docblock), never in an app service provider. A saved card's
 * token carries the outcome of `chargeSavedCard` (R100): the "always declines"
 * test card's token prefix is refused, every other token succeeds.
 */
final class FakePaymentGateway implements PaymentGateway
{
    public function driver(): string
    {
        return 'fake';
    }

    public function capture(Lesson $lesson, Money $amount, string $idempotencyKey): PaymentCaptureResult
    {
        return new PaymentCaptureResult(
            gatewayRef: 'fake_'.$idempotencyKey.'_'.Str::random(8),
            rawResponse: ['driver' => 'fake', 'idempotency_key' => $idempotencyKey, 'amount_fils' => $amount->toFils()],
        );
    }

    /**
     * @param  string  $selection  a `TestCard` value
     */
    public function saveCard(User $account, string $selection): SavedCard
    {
        $card = TestCard::from($selection);

        return new SavedCard(
            gatewayCustomerRef: 'fake_cus_'.Str::random(10),
            gatewayToken: $card->tokenPrefix().Str::random(10),
            brand: 'Test card',
            last4: $card->last4(),
            expMonth: 12,
            expYear: (int) now()->addYears(3)->format('Y'),
        );
    }

    public function chargeSavedCard(Lesson $lesson, Money $amount, PaymentMethod $method, string $idempotencyKey): PaymentCaptureResult
    {
        if (str_starts_with($method->gateway_token, TestCard::Declines->tokenPrefix())) {
            throw new PaymentCaptureException('card_declined');
        }

        return new PaymentCaptureResult(
            gatewayRef: 'fake_'.$idempotencyKey.'_'.Str::random(8),
            rawResponse: ['driver' => 'fake', 'idempotency_key' => $idempotencyKey, 'amount_fils' => $amount->toFils(), 'saved_card' => true],
        );
    }
}
