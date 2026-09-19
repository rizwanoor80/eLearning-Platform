<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Admin-authored markdown → HTML for public pages and content blocks. Raw HTML
 * in the source is stripped and unsafe link schemes are dropped, so an admin
 * cannot embed script or an inline handler here (unlike `head_scripts`, which
 * is admin-only and rendered raw by design).
 */
class Markdown
{
    public static function toHtml(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }
}
