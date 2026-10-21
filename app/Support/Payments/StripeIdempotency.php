<?php

namespace App\Support\Payments;

use App\Enums\PaymentGatewayMode;

/**
 * 25a (R266): what is sent to Stripe as the `Idempotency-Key` header. Stripe keeps a key per account for
 * 24 hours and answers a repeat with the first call's result, so a key that is the same in `test` and `live`
 * (or on the rehearsal and the production server, which share nothing but the code) could one day return a
 * stale answer from the other side. The mode and the environment are therefore part of every key that goes
 * to Stripe. The key the app stores and writes into Stripe metadata (`idempotency_key`) stays the raw one,
 * so the lookups that match it against metadata are unchanged.
 */
final class StripeIdempotency
{
    public static function key(PaymentGatewayMode $mode, string $key): string
    {
        return $mode->value.':'.(string) config('app.env').':'.$key;
    }
}
