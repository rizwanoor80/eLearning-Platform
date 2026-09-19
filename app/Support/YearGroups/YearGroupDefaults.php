<?php

namespace App\Support\YearGroups;

use App\Enums\CurriculumCode;
use App\Enums\LevelTier;
use Illuminate\Support\Facades\DB;

/**
 * The one source of the seeded year groups (R33), read by both the year-group
 * migration (which inserts them for curricula that already exist) and
 * `YearGroupSeeder`. Rows follow PRD §3's tier examples; every tier is one the
 * curriculum has (`CurriculumCode::tiers()`). The labels are defaults an admin
 * can relabel in the Filament CRUD — CBSE's "Grade N" is such a default.
 */
class YearGroupDefaults
{
    /**
     * @return array<string, list<array{code: string, label: string, sort: int, tier: LevelTier}>> keyed by curriculum code
     */
    public static function all(): array
    {
        return [
            CurriculumCode::Gcse->value => self::range('y', 'Year ', 7, 11, fn (int $n): LevelTier => $n <= 9 ? LevelTier::LowerSecondary : LevelTier::Exam1),
            CurriculumCode::ALevel->value => self::range('y', 'Year ', 12, 13, fn (int $n): LevelTier => LevelTier::Exam2),
            CurriculumCode::IbMyp->value => self::range('myp', 'MYP ', 1, 5, fn (int $n): LevelTier => $n <= 3 ? LevelTier::LowerSecondary : LevelTier::Exam1),
            CurriculumCode::IbDp->value => self::range('dp', 'DP ', 1, 2, fn (int $n): LevelTier => LevelTier::Exam2),
            CurriculumCode::Cbse->value => self::range('g', 'Grade ', 6, 12, fn (int $n): LevelTier => match (true) {
                $n <= 8 => LevelTier::LowerSecondary,
                $n <= 10 => LevelTier::Exam1,
                default => LevelTier::Exam2,
            }),
        ];
    }

    /**
     * Inserts every default row that does not exist yet for a curriculum that
     * does (keyed on curriculum + code) and never touches an existing row, so an
     * admin's relabel survives. Plain query builder: usable from a migration.
     *
     * @return int rows inserted
     */
    public static function insertMissing(): int
    {
        $inserted = 0;
        $defaults = self::all();
        $now = now();

        foreach (DB::table('curricula')->get(['id', 'code']) as $curriculum) {
            foreach ($defaults[$curriculum->code] ?? [] as $row) {
                $exists = DB::table('year_groups')->where('curriculum_id', $curriculum->id)->where('code', $row['code'])->exists();

                if (! $exists) {
                    DB::table('year_groups')->insert([
                        'curriculum_id' => $curriculum->id,
                        'code' => $row['code'],
                        'label' => $row['label'],
                        'sort' => $row['sort'],
                        'level_tier' => $row['tier']->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $inserted++;
                }
            }
        }

        return $inserted;
    }

    /**
     * @param  callable(int): LevelTier  $tier
     * @return list<array{code: string, label: string, sort: int, tier: LevelTier}>
     */
    private static function range(string $codePrefix, string $labelPrefix, int $from, int $to, callable $tier): array
    {
        $rows = [];

        foreach (range($from, $to) as $index => $n) {
            $rows[] = ['code' => $codePrefix.$n, 'label' => $labelPrefix.$n, 'sort' => $index + 1, 'tier' => $tier($n)];
        }

        return $rows;
    }
}
