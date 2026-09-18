<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    /**
     * Seeds a placeholder `tutor_agreement` page at version 1 so sub-cycle
     * 1b's agreement step has a real page/version pair to record on
     * `tutor_profiles.agreement_version`. The pages editor and the other
     * public pages (terms, privacy, safeguarding, about, contact) are
     * sub-cycle 2c's scope — built directly against DATA_MODEL.md's schema
     * here so no throwaway table is dropped later (migrations are
     * forward-only once CP0 is merged).
     */
    public function run(): void
    {
        $page = Page::query()->updateOrCreate(
            ['slug' => 'tutor_agreement'],
            [
                'title' => 'Tutor Agreement',
                'body' => 'DRAFT — replace before launch',
                'version' => 1,
                'published_at' => now(),
            ],
        );

        $page->versions()->updateOrCreate(
            ['version' => 1],
            [
                'title' => $page->title,
                'body' => $page->body,
                'published_at' => $page->published_at,
            ],
        );
    }
}
