<?php

namespace Database\Factories;

use App\Enums\RecurringSlotSkipReason;
use App\Models\RecurringSlot;
use App\Models\RecurringSlotSkip;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<RecurringSlotSkip>
 */
class RecurringSlotSkipFactory extends Factory
{
    /**
     * A not-yet-notified collision skip a week from now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recurring_slot_id' => RecurringSlot::factory(),
            'starts_at' => Date::now()->addWeek()->startOfHour(),
            'reason' => RecurringSlotSkipReason::LessonCollision,
            'notified_at' => null,
        ];
    }

    public function notified(): static
    {
        return $this->state(fn (array $attributes) => ['notified_at' => now()]);
    }
}
