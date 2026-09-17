<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    /**
     * The four CP1 defaults. Managed afterwards in Filament — adding or
     * deactivating a row here changes the onboarding wizard and approval
     * rule with no code change (CP1 acceptance).
     *
     * @var array<int, array{code: string, name: string, description: string}>
     */
    private const TYPES = [
        [
            'code' => 'permit',
            'name' => 'Tutoring permit',
            'description' => 'A scan of your current tutoring permit.',
        ],
        [
            'code' => 'id',
            'name' => 'ID or passport',
            'description' => 'A government-issued photo ID or passport.',
        ],
        [
            'code' => 'qualification',
            'name' => 'Qualifications',
            'description' => 'Certificates or transcripts supporting the subjects you teach.',
        ],
        [
            'code' => 'police_clearance',
            'name' => 'Police clearance',
            'description' => 'A recent police clearance / good conduct certificate.',
        ],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $sort => $type) {
            DocumentType::query()->updateOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'description' => $type['description'],
                    'required' => true,
                    'active' => true,
                    'sort' => $sort,
                ],
            );
        }
    }
}
