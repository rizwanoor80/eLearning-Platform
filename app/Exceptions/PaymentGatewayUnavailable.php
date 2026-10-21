<?php

namespace App\Exceptions;

/**
 * R259 (1): the gateway could not be reached, or answered with an error that says nothing about the
 * card (the network, Stripe down, a rate limit, a lock timeout). It is not a decline. The outcome of a
 * capture that was in flight is unknown, so the booking is not told "you were not charged": the lesson
 * is expired, the payment is marked failed with the PaymentIntent kept, and the parent retries with the
 * same hold, which the capture's own idempotency key makes safe. `payments:retry-refunds` reconciles
 * what is never retried.
 *
 * It is a `PaymentCaptureException` so `PaymentGateway::capture()`'s declared contract (a frozen file)
 * still covers it; a caller that must tell it from a decline catches it first.
 */
class PaymentGatewayUnavailable extends PaymentCaptureException
{
    public const REASON = 'gateway_unavailable';

    public function __construct(public readonly ?string $intentId = null, ?\Throwable $previous = null)
    {
        parent::__construct(self::REASON, previous: $previous);
    }
}
