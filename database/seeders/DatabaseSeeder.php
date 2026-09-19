<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. No demo tutors/parents yet.
     */
    public function run(): void
    {
        $this->call([
            CurriculumSeeder::class,
            SubjectSeeder::class,
            PriceBandSeeder::class,
            SettingsSeeder::class,
            DocumentTypeSeeder::class,
            PageSeeder::class,
            ContentBlockSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
