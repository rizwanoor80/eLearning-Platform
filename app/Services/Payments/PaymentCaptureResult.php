<?php

namespace App\Services\Payments;

final readonly class PaymentCaptureResult
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public string $gatewayRef,
        public array $rawResponse = [],
    ) {}
}
