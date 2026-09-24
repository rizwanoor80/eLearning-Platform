<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Default state: a captured fake-gateway payment for a fresh lesson, the
     * shape `BookLesson` writes on a successful capture.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'payer_user_id' => User::factory(),
            'payment_method_id' => null,
            'gateway' => 'fake',
            'gateway_ref' => 'fake_'.Str::random(12),
            'amount' => 10000,
            'currency' => 'AED',
            'status' => PaymentStatus::Captured,
            'refunded_amount' => null,
            'failure_reason' => null,
            'raw_response' => ['driver' => 'fake'],
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'gateway_ref' => null,
            'status' => PaymentStatus::Failed,
            'failure_reason' => 'card_declined',
        ]);
    }
}
