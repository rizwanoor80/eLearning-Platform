<?php

namespace App\Services\Payments;

use App\Models\Lesson;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * The only driver until CP5 adds a real one. Captures instantly and always
 * succeeds — bound only from the test side (see the PaymentGateway
 * interface's docblock), never in an app service provider.
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
}
