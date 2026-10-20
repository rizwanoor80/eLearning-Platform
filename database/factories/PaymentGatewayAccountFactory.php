<?php

namespace Database\Factories;

use App\Enums\PaymentGatewayCode;
use App\Enums\PaymentGatewayMode;
use App\Models\PaymentGatewayAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * @extends Factory<PaymentGatewayAccount>
 */
class PaymentGatewayAccountFactory extends Factory
{
    /**
     * A complete, inactive Stripe row in test mode with obviously fake credentials.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => PaymentGatewayCode::Stripe->value,
            'name' => PaymentGatewayCode::Stripe->label(),
            'mode' => PaymentGatewayMode::Test,
            'credentials' => ['publishable_key' => 'pk_test_example', 'secret_key' => 'sk_test_example', 'webhook_secret' => 'whsec_example'],
            'supports_saved_cards' => true,
            'is_active' => false,
            'sort' => 1,
        ];
    }

    public function fake(): static
    {
        return $this->state(fn () => [
            'code' => PaymentGatewayCode::Fake->value,
            'name' => PaymentGatewayCode::Fake->label(),
            'credentials' => null,
            'sort' => 2,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    /**
     * The migration inserts one row per code, so a created row is that row updated, never a second
     * row with the same code. Activating one deactivates the rest, as the Activate action does.
     *
     * @param  array<string, mixed>  $attributes
     * @return PaymentGatewayAccount|Collection<int, PaymentGatewayAccount>
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if ($this->count !== null) {
            return parent::create($attributes, $parent);
        }

        $raw = $this->state($attributes)->getExpandedAttributes($parent);

        if (($raw['is_active'] ?? false) === true) {
            PaymentGatewayAccount::query()->where('code', '!=', $raw['code'])->update(['is_active' => false]);
        }

        // Written inactive first so the guard sees the final credentials and mode, then switched.
        $row = PaymentGatewayAccount::query()->firstOrNew(['code' => $raw['code']]);
        $row->forceFill(Arr::except($raw, ['code', 'is_active']) + ['is_active' => false])->save();

        if (($raw['is_active'] ?? false) === true) {
            $row->forceFill(['is_active' => true])->save();
        }

        return $row->refresh();
    }
}
