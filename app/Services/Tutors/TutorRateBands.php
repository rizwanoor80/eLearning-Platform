<?php

namespace App\Services\Tutors;

use App\Enums\LevelTier;
use App\Models\Curriculum;
use App\Models\PriceBand;
use App\Models\TutorProfile;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * A tutor's permitted hourly rate: the intersection of the price bands of
 * every curriculum they teach at their highest level tier (R26/R27). Moved
 * out of `TutorOnboardingController` so the wizard and the approval check
 * (R36 f) read one rule; behaviour is unchanged. All amounts are integer fils.
 */
class TutorRateBands
{
    /**
     * Each curriculum the tutor teaches at their highest level tier, mapped
     * to its current `price_bands` row (latest `effective_from` not in the
     * future) or `null` if that curriculum has none — never hard-coded, so
     * an admin edit to the bands takes effect immediately, and a missing
     * band is surfaced rather than silently skipped (R27). Empty until the
     * tutor has at least one subject.
     *
     * @return Collection<int, array{curriculum: Curriculum, band: PriceBand|null}>
     */
    public function currentBandsFor(TutorProfile $profile): Collection
    {
        $tutorSubjects = $profile->tutorSubjects()->get(['curriculum_id', 'level_tier']);

        if ($tutorSubjects->isEmpty()) {
            return collect();
        }

        $highestTier = $tutorSubjects->pluck('level_tier')
            ->sortByDesc(fn (LevelTier $tier) => $tier->rank())
            ->first();

        $curriculumIds = $tutorSubjects->where('level_tier', $highestTier)->pluck('curriculum_id')->unique();

        /** @var Collection<int, array{curriculum: Curriculum, band: PriceBand|null}> $entries */
        $entries = Curriculum::query()->whereIn('id', $curriculumIds)->get()
            ->map(fn (Curriculum $curriculum) => [
                'curriculum' => $curriculum,
                'band' => PriceBand::query()
                    ->where('curriculum_id', $curriculum->id)
                    ->where('level_tier', $highestTier)
                    ->where('effective_from', '<=', now()->toDateString())
                    ->orderByDesc('effective_from')
                    ->first(),
            ])
            ->values();

        return $entries;
    }

    /**
     * R26/R27: a tutor's single `hourly_rate` must satisfy every curriculum
     * they teach at their highest tier — the intersection of each
     * curriculum's band, never the union (a union would accept a rate
     * outside one curriculum's real band). `conflicting` lists curricula by
     * name, never silently picking one, when: their bands don't overlap at
     * all (`min` ends up above `max`), or a curriculum has no current band
     * at all (R27 — previously silently dropped from the calculation).
     * Null only when the tutor has no subjects yet.
     *
     * @return array{min: int, max: int, conflicting: array<int, string>}|null
     */
    public function bandFor(TutorProfile $profile): ?array
    {
        $entries = $this->currentBandsFor($profile);

        if ($entries->isEmpty()) {
            return null;
        }

        $missing = $entries->filter(fn (array $entry) => $entry['band'] === null)
            ->map(fn (array $entry) => $entry['curriculum']->name);

        if ($missing->isNotEmpty()) {
            return ['min' => 0, 'max' => 0, 'conflicting' => $missing->values()->all()];
        }

        $min = $entries->max(fn (array $entry) => $entry['band']->min_rate->toFils());
        $max = $entries->min(fn (array $entry) => $entry['band']->max_rate->toFils());

        return [
            'min' => $min,
            'max' => $max,
            'conflicting' => $min > $max
                ? $entries->map(fn (array $entry) => $entry['curriculum']->name)->all()
                : [],
        ];
    }

    /**
     * Whether the tutor's stored `hourly_rate` is a valid rate today: set,
     * a band exists and does not conflict, and the rate lies inside it.
     */
    public function rateIsValid(TutorProfile $profile): bool
    {
        return $this->problemWithRate($profile) === null;
    }

    /**
     * The reason the stored rate is not valid today, or null when it is.
     */
    public function problemWithRate(TutorProfile $profile): ?string
    {
        if ($profile->hourly_rate === null) {
            return 'no hourly rate is set';
        }

        $band = $this->bandFor($profile);

        if ($band === null) {
            return 'no subjects are set, so there is no price band for the rate';
        }

        if ($band['conflicting'] !== []) {
            return sprintf('no single price band applies (%s)', implode(', ', $band['conflicting']));
        }

        $fils = $profile->hourly_rate->toFils();

        if ($fils < $band['min'] || $fils > $band['max']) {
            return sprintf(
                'the hourly rate %s is outside the current band %s to %s',
                $profile->hourly_rate->format(),
                Money::fils($band['min'])->format(),
                Money::fils($band['max'])->format(),
            );
        }

        return null;
    }
}
