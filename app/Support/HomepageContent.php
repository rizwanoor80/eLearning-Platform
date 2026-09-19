<?php

namespace App\Support;

use App\Models\ContentBlock;
use JsonException;

/**
 * Reads the homepage blocks from the database on every request and turns them
 * into safe props. A missing key, malformed JSON or a malformed item is
 * tolerated (it renders as nothing) — the homepage must never crash on copy an
 * admin has edited. Markdown is rendered with raw HTML stripped.
 */
class HomepageContent
{
    /**
     * `howItWorks` items carry `title` + `html`; `faq` items carry `question` + `html`.
     *
     * @return array{heroTitle: string, heroText: string, howItWorks: list<array<string, string>>, faq: list<array<string, string>>}
     */
    public static function build(): array
    {
        $blocks = ContentBlock::query()->whereIn('key', [
            ContentBlock::HERO_TITLE, ContentBlock::HERO_TEXT, ContentBlock::HOW_IT_WORKS, ContentBlock::FAQ,
        ])->pluck('body', 'key');

        return [
            'heroTitle' => trim((string) $blocks->get(ContentBlock::HERO_TITLE, '')),
            'heroText' => Markdown::toHtml($blocks->get(ContentBlock::HERO_TEXT)),
            'howItWorks' => self::items($blocks->get(ContentBlock::HOW_IT_WORKS), 'title', 'text', 'title'),
            'faq' => self::items($blocks->get(ContentBlock::FAQ), 'question', 'answer', 'question'),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private static function items(?string $json, string $headingKey, string $bodyKey, string $outputHeading): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $items = [];

        foreach ($decoded as $item) {
            if (! is_array($item) || ! is_string($item[$headingKey] ?? null) || trim($item[$headingKey]) === '') {
                continue;
            }

            $items[] = [
                $outputHeading => trim($item[$headingKey]),
                'html' => Markdown::toHtml(is_string($item[$bodyKey] ?? null) ? $item[$bodyKey] : ''),
            ];
        }

        return $items;
    }
}
