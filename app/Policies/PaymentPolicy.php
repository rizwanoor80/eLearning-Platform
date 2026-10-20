<?php

namespace App\Policies;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;

/**
 * A receipt belongs to the account that paid. Nobody else reads it through the parent area (an admin
 * sees payments in the admin panel, not as a parent's receipt), and a payment that never took money
 * (pending, failed, or without a gateway reference) has no receipt.
 */
class PaymentPolicy
{
    public function viewReceipt(User $user, Payment $payment): bool
    {
        return $payment->payer_user_id === $user->id && $this->isReceiptable($payment);
    }

    public function isReceiptable(Payment $payment): bool
    {
        return $payment->gateway_ref !== null && in_array($payment->status, self::paidStatuses(), true);
    }

    /**
     * @return list<PaymentStatus>
     */
    public static function paidStatuses(): array
    {
        return [PaymentStatus::Captured, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded];
    }
}
