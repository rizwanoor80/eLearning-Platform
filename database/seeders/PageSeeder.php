<?php

namespace Database\Seeders;

use App\Models\Page;
use App\Support\PublicPages;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    /**
     * Seeds the six admin-edited public pages at version 1 with a placeholder
     * body — and ONLY the ones that do not exist yet. An admin's published
     * edit, and every `page_versions` row, survive a re-seed; the 1b
     * `tutor_agreement` row is kept at its current version. (`pages` and
     * `page_versions` were built in 1b on DATA_MODEL's schema, so nothing needs
     * migrating into a new structure.)
     */
    public function run(): void
    {
        foreach (PublicPages::all() as $page) {
            $row = Page::query()->firstOrCreate(
                ['slug' => $page['slug']],
                [
                    'title' => $page['title'],
                    'body' => 'DRAFT — replace before launch',
                    'version' => 1,
                    'published_at' => now(),
                ],
            );

            if ($row->wasRecentlyCreated) {
                $row->versions()->create([
                    'version' => 1,
                    'title' => $row->title,
                    'body' => $row->body,
                    'published_at' => $row->published_at,
                ]);
            }
        }
    }
}
