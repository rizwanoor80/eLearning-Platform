<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. The demo weekly slot is local-only: rehearsal and
     * production must never gain a fake card.
     */
    public function run(): void
    {
        $this->call([
            CurriculumSeeder::class,
            YearGroupSeeder::class,
            SubjectSeeder::class,
            PriceBandSeeder::class,
            SettingsSeeder::class,
            DocumentTypeSeeder::class,
            PageSeeder::class,
            ContentBlockSeeder::class,
            AdminUserSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoRecurringSlotSeeder::class);
        }
    }
}
