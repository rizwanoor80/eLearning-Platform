<?php

namespace App\Actions\Payments;

use App\Actions\RecordAuditLog;
use App\Enums\PaymentMethodStatus;
use App\Enums\TestCard;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payments\FakePaymentGateway;
use RuntimeException;

/**
 * The fake driver's "Add a card" (R100): stores a chosen test card as the account's saved card.
 * One card per account in v1 — `payment_methods.account_user_id` is unique — so a second call
 * replaces the first. Holds a fake token, brand, last four and expiry only (invariant 15); the
 * page picks from two cards and never takes typed digits. CP5 replaces this with the real
 * driver's capture, and it refuses to run in production so it can never store a fake card there.
 *
 * Deliberately not resolved through the `PaymentGateway` interface: that binding does not exist
 * outside tests (invariant 16), and this is a fake-driver page, so it asks the fake driver's
 * `saveCard` directly. CP5 swaps in the registry's driver.
 */
class SaveTestCard
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * Whether the fake add-card page may be used here: never when `APP_ENV=production`.
     */
    public static function available(): bool
    {
        return ! app()->environment('production');
    }

    public function __invoke(User $account, TestCard $card): PaymentMethod
    {
        if (! self::available()) {
            throw new RuntimeException('Test cards cannot be saved in production.');
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
