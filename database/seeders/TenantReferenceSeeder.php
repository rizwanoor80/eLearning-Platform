<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The reference data every tenant needs to function (curricula, subjects, price bands, settings, document types,
 * pages, content blocks) and nothing else: no admin, no demo tutors, no demo parents. Every seeder here is idempotent.
 * `tenants:create` runs it; DatabaseSeeder runs it too, then adds the admin from the environment.
 */
class TenantReferenceSeeder extends Seeder
{
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
        ]);
    }
}
