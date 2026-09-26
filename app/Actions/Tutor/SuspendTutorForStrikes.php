<?php

namespace App\Actions\Tutor;

use App\Enums\StrikeType;
use App\Enums\TutorProfileStatus;
use App\Events\Tutor\TutorSuspendedForStrikes;
use App\Models\TutorProfile;
use App\Models\TutorStrike;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent, race-safe strike-count check: locks the profile row before
 * counting, so two strikes landing at once (e.g. two tutor-late-cancels
 * committing back to back) cannot both observe count=3 and both attempt the
 * transition — the second waits for the first's commit, then sees
 * `suspended` already and no-ops. Below the 3-in-90-days threshold, or a
 * tutor not currently `approved`, is also a silent no-op.
 */
class SuspendTutorForStrikes
{
    public function __construct(private SuspendTutor $suspendTutor) {}

    public function __invoke(TutorProfile $profile): void
    {
        DB::transaction(function () use ($profile) {
            $locked = TutorProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TutorProfileStatus::Approved) {
                return;
            }

            $strikeCount = TutorStrike::query()
                ->where('tutor_profile_id', $locked->id)
                // The late-report review row is history for the admins (PRD §2.7 rule 5), not a strike toward suspension.
                ->where('type', '!=', StrikeType::LateReportX3)
                ->where('created_at', '>=', now()->subDays(90))
                ->count();

            if ($strikeCount < 3) {
                return;
            }

            ($this->suspendTutor)(
                null,
                $locked,
                "Automatically suspended: {$strikeCount} strikes within the last 90 days.",
            );

            DB::afterCommit(fn () => TutorSuspendedForStrikes::dispatch($locked, $strikeCount));
        });
    }
}
