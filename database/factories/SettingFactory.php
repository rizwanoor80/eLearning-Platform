<?php

namespace Database\Factories;

use App\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->word(),
            'group' => SettingGroup::Platform,
            'value' => fake()->numberBetween(1, 100),
            'updated_at' => now(),
        ];
    }
}
