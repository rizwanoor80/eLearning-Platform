<?php

namespace App\Actions\Payments;

use App\Actions\RecordAuditLog;
use App\Enums\PaymentMethodStatus;
use App\Enums\TestCard;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The fake driver's "Add a card" (R100): stores a chosen test card as the account's saved card.
 * One card per account in v1 — `payment_methods.account_user_id` is unique — so a second call
 * replaces the first. Holds a fake token, brand, last four and expiry only (invariant 15); the
 * page picks from two cards and never takes typed digits. CP5 replaces this with the real
 * driver's capture, and it refuses to run in production so it can never store a fake card there.
 *
 * Deliberately not routed through the `PaymentGateway` interface: that binding does not exist
 * outside tests (invariant 16), and 4e adds `saveCard` and `chargeSavedCard` to it together.
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

        $method = PaymentMethod::query()->updateOrCreate(
            ['account_user_id' => $account->id],
            [
                'gateway' => 'fake',
                'gateway_customer_ref' => 'fake_cus_'.Str::random(10),
                'gateway_token' => $card->tokenPrefix().Str::random(10),
                'brand' => 'Test card',
                'last4' => $card->last4(),
                'exp_month' => 12,
                'exp_year' => (int) now()->addYears(3)->format('Y'),
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
