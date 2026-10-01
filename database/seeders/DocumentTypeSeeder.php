<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    /**
     * CP1's four defaults plus `cv` (R171). None are `required` any more: the permit and every
     * document type became optional at onboarding (R170/R171) — the admin can flip one back to
     * required in Filament later. Managed afterwards in Filament — adding or deactivating a row
     * here changes the onboarding wizard and approval rule with no code change (CP1 acceptance).
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
        [
            'code' => 'cv',
            'name' => 'CV / resume',
            'description' => 'A CV or resume, used for vetting instead of a tutoring permit.',
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
                    'required' => false,
                    'active' => true,
                    'sort' => $sort,
                ],
            );
        }
    }
}
