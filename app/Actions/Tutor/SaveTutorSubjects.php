<?php

namespace App\Actions\Tutor;

use App\Models\Curriculum;
use App\Models\TutorProfile;
use App\Models\YearGroup;
use App\Services\Tutors\TutorRateBands;
use App\Support\YearGroups\YearGroupTiers;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * R173(a) item 3: extracted from `TutorOnboardingController::storeSubjects()` so the Filament
 * admin edit-on-behalf action (R171) reuses the exact tier derivation, overlap/duplicate checks
 * and rate invalidation the wizard already enforces, rather than a second, drifting copy. Only
 * the per-row input shape is assumed valid here (`SubjectsStepRequest` validates that on the
 * wizard side; the admin form schema validates it on the Filament side) — every cross-row
 * business rule (curriculum/year-group pairing, range direction, tier existence, duplicates)
 * still runs here, since neither caller's input-shape validation can express it.
 */
class SaveTutorSubjects
{
    public function __construct(private TutorRateBands $rateBands) {}

    /**
     * @param  array<int, array{curriculum_id: int, subject_id: int, level_min_id: int, level_max_id: int}>  $subjectsInput
     *
     * @throws ValidationException when a curriculum/year-group pairing is invalid, the range is
     *                             inverted, the derived tier doesn't exist for the curriculum, or a
     *                             subject is listed twice under the same curriculum.
     */
    public function __invoke(TutorProfile $profile, array $subjectsInput): void
    {
        $rows = collect($subjectsInput);

        $curricula = Curriculum::query()->whereIn('id', $rows->pluck('curriculum_id')->unique())->get()->keyBy('id');
        $groups = YearGroup::query()->whereIn('id', $rows->pluck('level_min_id')->merge($rows->pluck('level_max_id'))->unique())->get()->keyBy('id');

        $seen = [];
        $tiers = [];
        foreach ($rows as $index => $row) {
            /** @var Curriculum|null $curriculum */
            $curriculum = $curricula->get($row['curriculum_id']);
            abort_if($curriculum === null, 422);

            $min = $groups->get($row['level_min_id']);
            $max = $groups->get($row['level_max_id']);

            // Both year groups must belong to this row's curriculum and run low to high.
            if ($min?->curriculum_id !== $curriculum->id || $max?->curriculum_id !== $curriculum->id) {
                throw ValidationException::withMessages([
                    'subjects' => "Choose year groups that belong to {$curriculum->name}.",
                ]);
            }

            if ($min->sort > $max->sort) {
                throw ValidationException::withMessages([
                    'subjects' => "The lowest year group must not come after the highest for {$curriculum->name}.",
                ]);
            }

            // The tier is derived from the year groups, never typed (R33).
            $tier = YearGroupTiers::derive($min, $max);
            if ($tier === null || ! in_array($tier, $curriculum->code->tiers(), true)) {
                throw ValidationException::withMessages([
                    'subjects' => "The level range does not exist for {$curriculum->code->value}.",
                ]);
            }
            $tiers[$index] = $tier;

            $key = $row['curriculum_id'].':'.$row['subject_id'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'subjects' => 'Each subject may only be listed once per curriculum.',
                ]);
            }
            $seen[$key] = true;
        }

        DB::transaction(function () use ($profile, $rows, $tiers): void {
            $profile->tutorSubjects()->delete();

            foreach ($rows as $index => $row) {
                $profile->tutorSubjects()->create([
                    'curriculum_id' => $row['curriculum_id'],
                    'subject_id' => $row['subject_id'],
                    'level_min_id' => $row['level_min_id'],
                    'level_max_id' => $row['level_max_id'],
                    'level_tier' => $tiers[$index],
                ]);
            }
        });

        $this->invalidateRateIfOutOfBand($profile);
    }

    /**
     * R27: a subjects change can move the tutor's highest tier or
     * curriculum set, so a previously valid `hourly_rate` may no longer
     * fit. Rather than leaving a stale rate in place — which let a tutor
     * reach `complete` with a rate outside their real band — clear it so
     * the wizard (or the admin's "what's missing" panel) sends them back
     * through the rate step with the correct band.
     */
    private function invalidateRateIfOutOfBand(TutorProfile $profile): void
    {
        if ($profile->hourly_rate === null) {
            return;
        }

        $band = $this->rateBands->bandFor($profile);
        $rateFils = $profile->hourly_rate->toFils();

        if ($band === null || $band['conflicting'] !== [] || $rateFils < $band['min'] || $rateFils > $band['max']) {
            $profile->update(['hourly_rate' => null]);
        }
    }
}
