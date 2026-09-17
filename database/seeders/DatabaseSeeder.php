<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. CP0 scope only: curricula, subjects,
     * price bands, platform-group settings, one admin user (R10). No demo
     * tutors/parents yet — TutorProfile doesn't exist until CP1.
     */
    public function run(): void
    {
        $this->call([
            CurriculumSeeder::class,
            SubjectSeeder::class,
            PriceBandSeeder::class,
            SettingsSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
