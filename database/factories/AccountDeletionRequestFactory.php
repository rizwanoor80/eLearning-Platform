<?php

namespace Database\Factories;

use App\Enums\AccountDeletionRequestStatus;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountDeletionRequest>
 */
class AccountDeletionRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'requested_at' => now(),
            'status' => AccountDeletionRequestStatus::Open,
        ];
    }

    public function declined(): static
    {
        return $this->state(fn (): array => [
            'status' => AccountDeletionRequestStatus::Declined,
            'handled_by' => User::factory()->admin(),
            'handled_at' => now(),
            'admin_note' => fake()->sentence(),
        ]);
    }

    public function erased(): static
    {
        return $this->state(fn (): array => [
            'status' => AccountDeletionRequestStatus::Erased,
            'handled_by' => User::factory()->admin(),
            'handled_at' => now(),
        ]);
    }
}
