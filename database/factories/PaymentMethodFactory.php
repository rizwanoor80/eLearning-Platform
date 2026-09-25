<?php

namespace Database\Factories;

use App\Enums\PaymentMethodStatus;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    /**
     * Default state: an active fake-gateway test card ("always succeeds", last four 4242).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_user_id' => User::factory(),
            'gateway' => 'fake',
            'gateway_customer_ref' => 'fake_cus_'.Str::random(10),
            'gateway_token' => 'fake_pm_succeeds_'.Str::random(10),
            'brand' => 'Test card',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => (int) now()->addYears(3)->format('Y'),
            'status' => PaymentMethodStatus::Active,
            'last_failed_at' => null,
        ];
    }

    public function declining(): static
    {
        return $this->state(fn (array $attributes) => [
            'gateway_token' => 'fake_pm_declines_'.Str::random(10),
            'last4' => '0002',
        ]);
    }
}
