<?php

namespace App\Support\Payments;

use App\Enums\PaymentGatewayMode;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Enums\RecurringSlotStatus;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentRefund;
use App\Models\RecurringSlot;

/**
 * R266 (4): what a Stripe mode switch would touch, counted from the database and never from Stripe. Read-only. Used by
 * `payments:mode-readiness` and by the confirmation on the gateway screen, so both say the same numbers.
 *
 * A reference exists in one Stripe mode only, so the switch is safe when nothing is in flight: no payment waiting on
 * Stripe, no refund still to be made, and every active weekly slot's card already in the target mode (or its parent
 * told to add it again).
 */
final class StripeModeReadiness
{
    /**
     * @return array{cards: array<string, int>, payments: array<string, int>, pending_payments: int, open_refunds: int, slots_needing_new_card: int, unusable_cards: int}
     */
    public function for(PaymentGatewayMode $target): array
    {
        $other = $target === PaymentGatewayMode::Live ? PaymentGatewayMode::Test : PaymentGatewayMode::Live;

        return [
            'cards' => $this->byMode(PaymentMethod::query()->live()->where('gateway', 'stripe')),
            'payments' => $this->byMode(Payment::query()->where('gateway', 'stripe')),
            // Waiting on Stripe now: the deploy and the switch should both happen with none.
            'pending_payments' => Payment::query()->where('gateway', 'stripe')->where('status', PaymentStatus::Pending)->count(),
            // Refunds still to be made, or refused, on payments of the mode being left: they cannot be made after it.
            'open_refunds' => PaymentRefund::query()
                ->whereIn('status', [PaymentRefundStatus::Requested->value, PaymentRefundStatus::Failed->value])
                ->whereHas('payment', fn ($q) => $q->where('gateway', 'stripe')->where('gateway_mode', $other->value))
                ->count(),
            'unusable_cards' => PaymentMethod::query()->live()->where('gateway', 'stripe')->where('gateway_mode', $other->value)->count(),
            'slots_needing_new_card' => RecurringSlot::query()
                ->where('status', RecurringSlotStatus::Active)
                ->whereHas('learner', fn ($learner) => $learner->whereIn('account_user_id',
                    PaymentMethod::query()->live()->where('gateway', 'stripe')->where('gateway_mode', $other->value)->select('account_user_id'),
                ))
                ->count(),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return array<string, int>
     */
    private function byMode($query): array
    {
        $counts = ['test' => 0, 'live' => 0];

        foreach ($query->selectRaw('gateway_mode, count(*) as total')->groupBy('gateway_mode')->pluck('total', 'gateway_mode') as $mode => $total) {
            $counts[(string) $mode] = (int) $total;
        }

        return $counts;
    }
}
