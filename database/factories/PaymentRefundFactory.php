<?php

namespace Database\Factories;

use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentRefund>
 */
class PaymentRefundFactory extends Factory
{
    /**
     * Default state: a refund the gateway made for a payment, outside the ledger (`unverified`), so no
     * ledger entry is needed. `ledger()` takes the entry.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'ledger_entry_id' => null,
            'kind' => PaymentRefundKind::Unverified,
            'attempt' => 1,
            'idempotency_key' => 'unverified-'.Str::random(12),
            'amount' => 10000,
            'gateway_refund_id' => 're_'.Str::random(12),
            'gateway_status' => 'succeeded',
            'status' => PaymentRefundStatus::Succeeded,
            'failure_reason' => null,
            'requested_at' => now(),
            'completed_at' => now(),
        ];
    }

    public function failed(string $reason = 'refund_failed'): static
    {
        return $this->state(fn () => [
            'gateway_refund_id' => null,
            'gateway_status' => null,
            'status' => PaymentRefundStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }

    public function requested(): static
    {
        return $this->state(fn () => [
            'gateway_refund_id' => null,
            'gateway_status' => null,
            'status' => PaymentRefundStatus::Requested,
            'completed_at' => null,
        ]);
    }

    public function external(): static
    {
        return $this->state(fn () => [
            'kind' => PaymentRefundKind::External,
            'idempotency_key' => null,
        ]);
    }
}
