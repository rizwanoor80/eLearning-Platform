<?php

namespace Database\Factories;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispute>
 */
class DisputeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'opened_by_user_id' => User::factory(),
            'reason' => DisputeReason::Quality,
            'description' => fake()->paragraph(),
            'status' => DisputeStatus::Open,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => DisputeStatus::Resolved,
            'parent_refund_pct' => 100,
            'tutor_pay_pct' => 0,
            'refund_amount' => 0,
            'tutor_paid_amount' => 0,
            'platform_delta' => 0,
            'admin_note' => fake()->sentence(),
            'resolved_by' => User::factory(),
            'resolved_at' => now(),
        ]);
    }
}
