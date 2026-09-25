<?php

namespace App\Actions\Payments;

use App\Actions\RecordAuditLog;
use App\Enums\PaymentMethodStatus;
use App\Enums\TestCard;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Providers\PaymentGatewayServiceProvider;
use App\Services\Payments\FakePaymentGateway;
use RuntimeException;

/**
 * The fake driver's "Add a card" (R100): stores a chosen test card as the account's saved card.
 * One card per account in v1 — `payment_methods.account_user_id` is unique — so a second call
 * replaces the first. Holds a fake token, brand, last four and expiry only (invariant 15); the
 * page picks from two cards and never takes typed digits. CP5 replaces this with the real
 * driver's capture, and it refuses to run anywhere but the fake-gateway environments so a fake card is never stored on production.
 *
 * Deliberately not resolved through the `PaymentGateway` interface: this is a fake-driver page,
 * so it asks the fake driver's `saveCard` directly, and only on the environments that run the fake
 * gateway (R107, ADR-016). CP5 swaps in the registry's driver.
 */
class SaveTestCard
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * Whether the fake add-card page may be used here: only where the fake gateway is bound — an
     * allow-list, so `production` and any unlisted `APP_ENV` are refused (R100, R107).
     */
    public static function available(): bool
    {
        return PaymentGatewayServiceProvider::fakeGatewayAllowed();
    }

    public function __invoke(User $account, TestCard $card): PaymentMethod
    {
        if (! self::available()) {
            throw new RuntimeException('Test cards can be saved only where the fake gateway runs.');
        }

        $saved = (new FakePaymentGateway)->saveCard($account, $card->value);

        $method = PaymentMethod::query()->updateOrCreate(
            ['account_user_id' => $account->id],
            [
                'gateway' => 'fake',
                'gateway_customer_ref' => $saved->gatewayCustomerRef,
                'gateway_token' => $saved->gatewayToken,
                'brand' => $saved->brand,
                'last4' => $saved->last4,
                'exp_month' => $saved->expMonth,
                'exp_year' => $saved->expYear,
                'status' => PaymentMethodStatus::Active,
                'last_failed_at' => null,
            ],
        );

        ($this->audit)($account, 'payment_method.saved', $method, null, [
            'gateway' => 'fake',
            'last4' => $card->last4(),
            'card' => $card->value,
        ]);

        return $method;
    }
}
