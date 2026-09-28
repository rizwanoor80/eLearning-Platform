<?php

namespace Database\Factories;

use App\Enums\AbuseReportReason;
use App\Enums\AbuseReportStatus;
use App\Enums\AbuseReportSubjectType;
use App\Models\AbuseReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AbuseReport>
 */
class AbuseReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reporter_user_id' => User::factory(),
            'subject_type' => AbuseReportSubjectType::User,
            'subject_id' => User::factory(),
            'reason' => AbuseReportReason::Safety,
            'description' => fake()->paragraph(),
            'status' => AbuseReportStatus::Open,
        ];
    }

    public function reviewing(): static
    {
        return $this->state(fn (): array => ['status' => AbuseReportStatus::Reviewing]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => AbuseReportStatus::Closed,
            'action_taken' => fake()->sentence(),
            'handled_by' => User::factory(),
            'closed_at' => now(),
        ]);
    }
}
