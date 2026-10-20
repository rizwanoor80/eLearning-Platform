<?php

namespace App\Actions\Payments;

use App\Actions\RecordAuditLog;
use App\Models\PaymentGatewayAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns the active gateway off. Booking then shows a notice and creates no `payments` row, and the
 * weekly charge job charges nothing, until an admin activates one.
 */
class DeactivatePaymentGateway
{
    public function __invoke(PaymentGatewayAccount $gateway, ?User $actor): void
    {
        if (! $gateway->is_active) {
            return;
        }

        DB::transaction(function () use ($gateway, $actor): void {
            $gateway->forceFill(['is_active' => false, 'updated_by' => $actor?->id])->save();

            app(RecordAuditLog::class)($actor, 'payment_gateway.deactivated', $gateway, ['active' => $gateway->code], ['active' => null]);
        });
    }
}
