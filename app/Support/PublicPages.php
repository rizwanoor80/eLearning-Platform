<?php

namespace App\Support;

use App\Models\Page;

/**
 * The fixed set of admin-edited public pages: URL path → `pages.slug`, with the
 * default title used when a page row is first seeded. The footer and the public
 * routes both read this one list, and the titles shown come from the database.
 */
class PublicPages
{
    /**
     * @return array<string, array{slug: string, title: string}>
     */
    public static function all(): array
    {
        return [
            'terms' => ['slug' => 'terms', 'title' => 'Terms of service'],
            'privacy' => ['slug' => 'privacy', 'title' => 'Privacy policy'],
            'tutor-agreement' => ['slug' => 'tutor_agreement', 'title' => 'Tutor agreement'],
            'safeguarding' => ['slug' => 'safeguarding', 'title' => 'Safeguarding'],
            'about' => ['slug' => 'about', 'title' => 'About us'],
            'contact' => ['slug' => 'contact', 'title' => 'Contact us'],
        ];
    }

    /**
     * Footer links, in the fixed order, for pages that exist right now.
     *
     * @return list<array{title: string, href: string}>
     */
    public static function footerLinks(): array
    {
        $titles = Page::query()->whereIn('slug', array_column(self::all(), 'slug'))->pluck('title', 'slug');
        $links = [];

        foreach (self::all() as $path => $page) {
            if ($titles->has($page['slug'])) {
                $links[] = ['title' => (string) $titles->get($page['slug']), 'href' => '/'.$path];
            }
        }

        return $links;
    }
}
