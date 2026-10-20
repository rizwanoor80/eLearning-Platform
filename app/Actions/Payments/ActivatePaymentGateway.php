<?php

namespace App\Actions\Payments;

use App\Actions\RecordAuditLog;
use App\Models\PaymentGatewayAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Makes one registry row the active payment gateway and deactivates the rest, in one transaction so
 * the one-active index never trips. The row's own guard (`PaymentGatewayAccount::
 * activationProblem()`) refuses the fake gateway outside its environments, a code with no driver,
 * incomplete credentials, live mode while live is not allowed, and a gateway without saved cards
 * while weekly slots are active — whether this action or anything else saves the row.
 */
class ActivatePaymentGateway
{
    /**
     * @throws LogicException when the row may not be active
     */
    public function __invoke(PaymentGatewayAccount $gateway, ?User $actor): void
    {
        if ($gateway->is_active) {
            return;
        }

        if (($problem = $gateway->activationProblem()) !== null) {
            throw new LogicException($problem);
        }

        DB::transaction(function () use ($gateway, $actor): void {
            // Lock every row first so two admins activating at once queue here instead of one of them
            // meeting the one-active index.
            PaymentGatewayAccount::query()->lockForUpdate()->get();

            $previous = PaymentGatewayAccount::query()->active()->value('code');

            PaymentGatewayAccount::query()->where('is_active', true)->update(['is_active' => false]);

            $gateway->forceFill(['is_active' => true, 'updated_by' => $actor?->id])->save();

            app(RecordAuditLog::class)($actor, 'payment_gateway.activated', $gateway, ['active' => $previous], ['active' => $gateway->code]);
        });
    }
}
