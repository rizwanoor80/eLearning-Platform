<?php

namespace Database\Seeders;

use App\Support\YearGroups\YearGroupDefaults;
use Illuminate\Database\Seeder;

class YearGroupSeeder extends Seeder
{
    /**
     * The default year groups for every seeded curriculum (run after
     * CurriculumSeeder). Insert-missing only: an admin's relabel, retier or
     * added group survives a re-seed.
     */
    public function run(): void
    {
        YearGroupDefaults::insertMissing();
    }
}
