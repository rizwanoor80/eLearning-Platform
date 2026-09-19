<?php

namespace App\Support\YearGroups;

use App\Models\TutorSubject;
use App\Models\YearGroup;
use Illuminate\Support\Facades\DB;

/**
 * Maps the free-text year groups of the old columns (kept as `*_legacy`) onto
 * the controlled list (R33): a trimmed, whitespace-collapsed, case-insensitive
 * exact match on the label within the row's own curriculum. A match sets the
 * foreign key and clears the legacy text; anything else — no match, or a
 * learner with no curriculum — is left with a null key and its text intact for
 * the report. Idempotent: it only touches rows that still have legacy text and
 * no key, so it can be run again after a label is added or corrected.
 * Plain query builder throughout, so the migration can call it.
 */
class LegacyYearGroupMapper
{
    /**
     * @return array{learners: array{matched: int, unmatched: int}, tutor_subjects: array{matched: int, unmatched: int, tiers_changed: int}}
     */
    public function run(): array
    {
        $learners = DB::update(
            'UPDATE learners SET year_group_id = yg.id, year_group_legacy = NULL
             FROM year_groups yg
             WHERE learners.year_group_id IS NULL AND learners.year_group_legacy IS NOT NULL
               AND yg.curriculum_id = learners.curriculum_id
               AND '.self::norm('yg.label').' = '.self::norm('learners.year_group_legacy'),
        );

        $subjects = 0;

        foreach (['min', 'max'] as $end) {
            $subjects += DB::update(
                "UPDATE tutor_subjects SET level_{$end}_id = yg.id, level_{$end}_legacy = NULL
                 FROM year_groups yg
                 WHERE tutor_subjects.level_{$end}_id IS NULL AND tutor_subjects.level_{$end}_legacy IS NOT NULL
                   AND yg.curriculum_id = tutor_subjects.curriculum_id
                   AND ".self::norm('yg.label').' = '.self::norm("tutor_subjects.level_{$end}_legacy"),
            );
        }

        return [
            'learners' => [
                'matched' => $learners,
                'unmatched' => DB::table('learners')->whereNull('year_group_id')->whereNotNull('year_group_legacy')->count(),
            ],
            'tutor_subjects' => [
                'matched' => $subjects,
                'unmatched' => DB::table('tutor_subjects')->where(fn ($q) => $q->where(fn ($m) => $m->whereNull('level_min_id')->whereNotNull('level_min_legacy'))
                    ->orWhere(fn ($m) => $m->whereNull('level_max_id')->whereNotNull('level_max_legacy')))->count(),
                'tiers_changed' => $this->rederiveTiers(),
            ],
        ];
    }

    /**
     * Rows that could not be mapped, for the report. Carries the table, row id,
     * curriculum code and the original text — never a person's name.
     *
     * @return list<array{table: string, id: int, curriculum: string|null, field: string, text: string}>
     */
    public function unmatched(): array
    {
        $rows = [];

        $learners = DB::table('learners')
            ->leftJoin('curricula', 'curricula.id', '=', 'learners.curriculum_id')
            ->whereNull('learners.year_group_id')->whereNotNull('learners.year_group_legacy')
            ->orderBy('learners.id')->get(['learners.id', 'curricula.code', 'learners.year_group_legacy']);

        foreach ($learners as $row) {
            $rows[] = ['table' => 'learners', 'id' => (int) $row->id, 'curriculum' => $row->code, 'field' => 'year_group', 'text' => (string) $row->year_group_legacy];
        }

        foreach (['min', 'max'] as $end) {
            $subjects = DB::table('tutor_subjects')
                ->leftJoin('curricula', 'curricula.id', '=', 'tutor_subjects.curriculum_id')
                ->whereNull("tutor_subjects.level_{$end}_id")->whereNotNull("tutor_subjects.level_{$end}_legacy")
                ->orderBy('tutor_subjects.id')->get(['tutor_subjects.id', 'curricula.code', "tutor_subjects.level_{$end}_legacy as legacy"]);

            foreach ($subjects as $row) {
                $rows[] = ['table' => 'tutor_subjects', 'id' => (int) $row->id, 'curriculum' => $row->code, 'field' => "level_{$end}", 'text' => (string) $row->legacy];
            }
        }

        return $rows;
    }

    /**
     * A row with both ends mapped gets its tier derived from the year groups,
     * replacing the tier that was typed under the old free-text form.
     */
    private function rederiveTiers(): int
    {
        $changed = 0;

        $rows = TutorSubject::query()->whereNotNull('level_min_id')->whereNotNull('level_max_id')->get();
        $groups = YearGroup::query()->whereIn('id', $rows->pluck('level_min_id')->merge($rows->pluck('level_max_id'))->unique())->get()->keyBy('id');

        foreach ($rows as $row) {
            $tier = YearGroupTiers::derive($groups->get($row->level_min_id), $groups->get($row->level_max_id));

            if ($tier !== null && $tier !== $row->level_tier) {
                DB::table('tutor_subjects')->where('id', $row->id)->update(['level_tier' => $tier->value]);
                $changed++;
            }
        }

        return $changed;
    }

    private static function norm(string $column): string
    {
        return "lower(regexp_replace(trim({$column}), '\\s+', ' ', 'g'))";
    }
}
