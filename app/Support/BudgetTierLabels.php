<?php

namespace App\Support;

use App\Enums\BudgetTier;
use App\Models\ContentBlock;

/**
 * The words shown for each match-request budget tier (R35). They live in three
 * content blocks so an admin can relabel without a deploy; the stored request
 * keeps only the tier value, so relabelling never rewrites history. A missing
 * block falls back to the tier's built-in default.
 */
class BudgetTierLabels
{
    /**
     * @return array<string, string> tier value => label
     */
    public static function all(): array
    {
        $blocks = ContentBlock::query()->whereIn('key', array_map(self::key(...), BudgetTier::cases()))->pluck('body', 'key');
        $labels = [];

        foreach (BudgetTier::cases() as $tier) {
            $custom = trim((string) $blocks->get(self::key($tier), ''));
            $labels[$tier->value] = $custom !== '' ? $custom : $tier->label();
        }

        return $labels;
    }

    public static function label(BudgetTier $tier): string
    {
        return self::all()[$tier->value];
    }

    /**
     * The content-block key that holds this tier's label.
     */
    public static function key(BudgetTier $tier): string
    {
        return 'match_budget_'.$tier->value;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(self::key(...), BudgetTier::cases());
    }
}
