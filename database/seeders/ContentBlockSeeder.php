<?php

namespace Database\Seeders;

use App\Models\ContentBlock;
use Illuminate\Database\Seeder;

class ContentBlockSeeder extends Seeder
{
    /**
     * The four homepage blocks with placeholder copy, inserted only when
     * missing — an admin's edit survives a re-seed.
     */
    public function run(): void
    {
        $placeholders = [
            ContentBlock::HERO_TITLE => 'DRAFT — replace before launch',
            ContentBlock::HERO_TEXT => 'DRAFT — replace before launch',
            ContentBlock::HOW_IT_WORKS => json_encode([
                ['title' => 'Tell us what you need', 'text' => 'DRAFT — replace before launch'],
                ['title' => 'Meet your tutor', 'text' => 'DRAFT — replace before launch'],
                ['title' => 'Learn online', 'text' => 'DRAFT — replace before launch'],
            ], JSON_THROW_ON_ERROR),
            ContentBlock::FAQ => json_encode([
                ['question' => 'DRAFT — replace before launch', 'answer' => 'DRAFT — replace before launch'],
            ], JSON_THROW_ON_ERROR),
        ];

        foreach ($placeholders as $key => $body) {
            ContentBlock::query()->firstOrCreate(['key' => $key], ['body' => $body]);
        }
    }
}
