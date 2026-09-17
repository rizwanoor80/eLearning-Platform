<?php

namespace Database\Seeders;

use App\Enums\CurriculumCode;
use App\Models\Curriculum;
use Illuminate\Database\Seeder;

class CurriculumSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const NAMES = [
        'GCSE' => 'GCSE / IGCSE',
        'A_LEVEL' => 'A-Level',
        'IB_MYP' => 'IB Middle Years Programme',
        'IB_DP' => 'IB Diploma Programme',
        'CBSE' => 'CBSE',
    ];

    public function run(): void
    {
        foreach (CurriculumCode::cases() as $sort => $code) {
            Curriculum::query()->updateOrCreate(
                ['code' => $code],
                ['name' => self::NAMES[$code->value], 'sort' => $sort],
            );
        }
    }
}
