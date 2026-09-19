<?php

namespace App\Actions\YearGroups;

use App\Actions\RecordAuditLog;
use App\Models\TutorSubject;
use App\Models\User;
use App\Models\YearGroup;
use App\Support\YearGroups\YearGroupTiers;
use Illuminate\Support\Facades\DB;

/**
 * After a year group's tier or sort is edited, the stored `level_tier` of every
 * tutor subject row of that curriculum is worked out again from the year groups
 * (R33: the tier is derived, never typed). One audit row records how many rows
 * changed and how many could not be re-derived (a sort edit that left a row's
 * lowest year group after its highest — the tier is left as it was).
 *
 * Deliberately NOT done here: clearing an approved tutor's rate that a new tier
 * pushes out of its band. The rate is re-checked at completion (R27), at
 * approval (R36f, cycle 03 step 3) and at booking (CP3) — this only keeps the
 * stored tier true.
 */
class RederiveSubjectTiers
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @return array{changed: int, invalid: int}
     */
    public function __invoke(?User $admin, YearGroup $group): array
    {
        $changed = 0;
        $invalid = 0;

        $rows = TutorSubject::query()
            ->where('curriculum_id', $group->curriculum_id)
            ->whereNotNull('level_min_id')->whereNotNull('level_max_id')
            ->with(['levelMin', 'levelMax'])
            ->get();

        foreach ($rows as $row) {
            $tier = YearGroupTiers::derive($row->levelMin, $row->levelMax);

            if ($tier === null) {
                $invalid++;

                continue;
            }

            if ($tier !== $row->level_tier) {
                DB::table('tutor_subjects')->where('id', $row->id)->update(['level_tier' => $tier->value]);
                $changed++;
            }
        }

        ($this->recordAuditLog)($admin, 'year_group.tiers_rederived', $group, null, ['rows_changed' => $changed, 'rows_invalid' => $invalid]);

        return ['changed' => $changed, 'invalid' => $invalid];
    }
}
