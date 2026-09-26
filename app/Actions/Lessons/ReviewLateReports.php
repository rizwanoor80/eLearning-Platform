<?php

namespace App\Actions\Lessons;

use App\Enums\StrikeType;
use App\Events\Tutor\TutorLateReportsFlagged;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\TutorStrike;
use Illuminate\Support\Facades\DB;

/**
 * PRD §2.7 rule 5: three late-report flags in 90 days send the tutor to admin review. The flags are the
 * tutor's lessons with `report_late_at` set in the window — counted, never stored as a number. The result
 * is one `late_report_x3` strike row (history) and one email to the admins; it does not suspend anyone.
 *
 * Locks the profile row before counting, as `SuspendTutorForStrikes` does, so two releases landing at once
 * cannot both cross the line, and a fourth late report in the same window finds the strike already there
 * and is a no-op.
 */
class ReviewLateReports
{
    public const THRESHOLD = 3;

    public const WINDOW_DAYS = 90;

    /**
     * @return bool true when this call opened a review
     */
    public function __invoke(TutorProfile $profile): bool
    {
        return DB::transaction(function () use ($profile): bool {
            $locked = TutorProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();
            $since = now()->subDays(self::WINDOW_DAYS);

            $flags = Lesson::query()
                ->where('tutor_profile_id', $locked->id)
                ->where('report_late_at', '>=', $since)
                ->orderByDesc('report_late_at')
                ->get(['id']);

            if ($flags->count() < self::THRESHOLD) {
                return false;
            }

            $open = TutorStrike::query()
                ->where('tutor_profile_id', $locked->id)
                ->where('type', StrikeType::LateReportX3)
                ->where('created_at', '>=', $since)
                ->exists();

            if ($open) {
                return false;
            }

            TutorStrike::query()->create([
                'tutor_profile_id' => $locked->id,
                'lesson_id' => $flags->first()->id,
                'type' => StrikeType::LateReportX3,
                'note' => self::THRESHOLD.' late progress reports within '.self::WINDOW_DAYS.' days; sent to admin review.',
            ]);

            $count = $flags->count();

            DB::afterCommit(fn () => TutorLateReportsFlagged::dispatch($locked, $count));

            return true;
        });
    }
}
