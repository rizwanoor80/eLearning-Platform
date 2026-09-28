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

    /**
     * The trial default (R150): 100% refund, 0% tutor pay. `refund_amount` matches (the lesson's
     * frozen price is not known here, so this state is for tests that only need a resolved status
     * and consistent dials, not for asserting real ledger amounts — pass explicit amounts via
     * `->state()` when a test needs those to match a real `settle()` call).
     */
    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => DisputeStatus::Resolved,
            'parent_refund_pct' => 100,
            'tutor_pay_pct' => 0,
            'tutor_paid_amount' => 0,
            'admin_note' => fake()->sentence(),
            'resolved_by' => User::factory(),
            'resolved_at' => now(),
        ]);
    }
}
