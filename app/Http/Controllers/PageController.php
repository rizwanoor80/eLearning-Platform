<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Support\Markdown;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public pages (terms, privacy, tutor agreement, safeguarding, about,
 * contact): the current published version, rendered from markdown on every
 * request so a publish shows immediately. A page row that does not exist is a 404.
 */
class PageController extends Controller
{
    public function __invoke(string $slug): Response
    {
        $page = Page::query()->where('slug', $slug)->firstOrFail();

        return Inertia::render('pages/Show', [
            'title' => $page->title,
            'html' => Markdown::toHtml($page->body),
            'version' => $page->version,
            'publishedAt' => $page->published_at?->toIso8601String(),
        ]);
    }
}
