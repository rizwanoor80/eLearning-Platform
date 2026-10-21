<?php

namespace App\Actions\Payments;

use App\Actions\RecordAuditLog;
use App\Enums\PaymentMethodStatus;
use App\Exceptions\PaymentCaptureException;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payments\NoActivePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Payments\ActiveStripe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * R248: stores the card a confirmed SetupIntent produced as the account's saved card. One card per
 * account in v1, so this replaces the previous one in place (the row id, and so every payment that
 * points at it, stays). The gateway driver checks that the SetupIntent is complete and belongs to this
 * account; only its token, brand, last four and expiry are kept (invariant 15). A replaced Stripe card
 * is detached from the customer afterwards, best effort: the weekly job reads only our row.
 */
class SaveStripeCard
{
    public function __construct(
        private readonly ActiveStripe $stripe,
        private readonly PaymentGatewayManager $gateways,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @throws NoActivePaymentGateway
     * @throws PaymentCaptureException when the SetupIntent is not a finished card setup of this account
     */
    public function __invoke(User $account, string $setupIntentId): PaymentMethod
    {
        $row = $this->stripe->row() ?? throw new NoActivePaymentGateway('Stripe is not the active payment gateway.');
        $saved = $this->gateways->forRow($row)->saveCard($account, $setupIntentId);

        // The live row is locked, so a removal that commits first leaves this to create a new row instead of
        // writing the new token onto the removed one.
        [$method, $previousToken] = DB::transaction(function () use ($account, $saved): array {
            $live = PaymentMethod::query()->live()->where('account_user_id', $account->id)->lockForUpdate()->first();
            $previousToken = $live?->gateway === 'stripe' ? $live->gateway_token : null;
            $attributes = [
                'gateway' => 'stripe',
                'gateway_customer_ref' => $saved->gatewayCustomerRef,
                'gateway_token' => $saved->gatewayToken,
                'brand' => $saved->brand,
                'last4' => $saved->last4,
                'exp_month' => $saved->expMonth,
                'exp_year' => $saved->expYear,
                'status' => PaymentMethodStatus::Active,
                'last_failed_at' => null,
            ];

            if ($live === null) {
                return [PaymentMethod::query()->create(['account_user_id' => $account->id] + $attributes), null];
            }

            $live->update($attributes);

            return [$live, $previousToken];
        });

        ($this->audit)($account, 'payment_method.saved', $method, null, ['gateway' => 'stripe', 'last4' => $saved->last4]);

        if ($previousToken !== null && $previousToken !== $saved->gatewayToken) {
            try {
                $this->stripe->client()->paymentMethods->detach($previousToken);
            } catch (Throwable $e) {
                Log::warning('Could not detach a replaced Stripe card.', ['account_user_id' => $account->id, 'error' => $e::class]);
            }
        }

        return $method;
    }
}
