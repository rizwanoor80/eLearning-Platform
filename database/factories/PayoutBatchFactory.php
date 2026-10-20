<?php

namespace Database\Factories;

use App\Models\PayoutBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutBatch>
 */
class PayoutBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'period_start' => null,
            'period_end' => now()->toDateString(),
            'created_by_user_id' => User::factory()->admin(),
        ];
    }
}
