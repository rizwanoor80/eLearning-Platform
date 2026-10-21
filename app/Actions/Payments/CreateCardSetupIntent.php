<?php

namespace App\Actions\Payments;

use App\Enums\PaymentGatewayMode;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Payments\ActiveStripe;
use App\Support\Payments\StripeIdempotency;

/**
 * R248: the SetupIntent the card page's Stripe Elements form confirms. The account keeps one Stripe
 * customer — the one on its saved Stripe card, else one created here (idempotent per account for a
 * day, so a double click does not make two) — and the new card is attached to it, so replacing a
 * card never orphans a customer. `usage: off_session` is what lets the weekly job charge it later.
 * Nothing about the parent is sent to Stripe but our own account id (no name, email or phone).
 */
class CreateCardSetupIntent
{
    public function __construct(private readonly ActiveStripe $stripe) {}

    /**
     * @return array{id: string, client_secret: string}
     */
    public function __invoke(User $account): array
    {
        $client = $this->stripe->client();
        $mode = $this->stripe->row()->mode;

        // R266: a customer exists in one Stripe mode only, so only one made in the active mode is reused. A
        // card saved before the mode column existed was made in test (null reads as test).
        $customer = PaymentMethod::query()
            ->where('account_user_id', $account->id)
            ->where('gateway', 'stripe')
            ->where(fn ($query) => $mode === PaymentGatewayMode::Test
                ? $query->whereNull('gateway_mode')->orWhere('gateway_mode', $mode->value)
                : $query->where('gateway_mode', $mode->value))
            ->latest('id') // a removed card's customer is still the account's customer: reuse it
            ->value('gateway_customer_ref');

        if (! is_string($customer) || $customer === '') {
            $customer = $client->customers->create(
                ['metadata' => ['account_user_id' => (string) $account->id]],
                ['idempotency_key' => StripeIdempotency::key($mode, 'customer-account-'.$account->id)],
            )->id;
        }

        $setup = $client->setupIntents->create([
            'customer' => $customer,
            'usage' => 'off_session',
            'payment_method_types' => ['card'],
            'metadata' => ['kind' => 'card_setup', 'account_user_id' => (string) $account->id],
        ]);

        return ['id' => $setup->id, 'client_secret' => (string) $setup->client_secret];
    }
}
