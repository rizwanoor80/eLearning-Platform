<?php

namespace Database\Factories;

use App\Enums\RecurringSlotStatus;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<RecurringSlot>
 */
class RecurringSlotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tutor_profile_id' => TutorProfile::factory(),
            'weekday' => 2,
            'start_time' => '17:00',
            'timezone' => 'Asia/Dubai',
            'starts_on' => Date::today(),
            'ends_on' => null,
            'status' => RecurringSlotStatus::Active,
        ];
    }
}
