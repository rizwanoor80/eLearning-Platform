<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SubjectSeeder extends Seeder
{
    /**
     * ~25 subjects common across the curricula seeded by CurriculumSeeder.
     * Not curriculum-specific — DATA_MODEL keeps `subjects` global.
     *
     * @var array<int, string>
     */
    private const NAMES = [
        'Mathematics',
        'Further Mathematics',
        'Physics',
        'Chemistry',
        'Biology',
        'Combined Science',
        'English Language',
        'English Literature',
        'History',
        'Geography',
        'Economics',
        'Business Studies',
        'Accounting',
        'Computer Science',
        'Psychology',
        'Sociology',
        'Arabic',
        'French',
        'Spanish',
        'Hindi',
        'Art and Design',
        'Music',
        'Physical Education',
        'Religious Studies',
        'Environmental Systems and Societies',
    ];

    public function run(): void
    {
        foreach (self::NAMES as $sort => $name) {
            Subject::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'sort' => $sort],
            );
        }
    }
}
