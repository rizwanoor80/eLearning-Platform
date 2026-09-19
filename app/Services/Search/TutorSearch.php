<?php

namespace App\Services\Search;

use App\Models\TutorProfile;
use App\Models\YearGroup;
use App\Services\Scheduling\Slot;
use App\Services\Scheduling\SlotCalculator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tutor search (CP2). Starts from `TutorProfile::bookable()` (invariant #5) —
 * nothing here re-implements what "bookable" means — then narrows in SQL
 * (curriculum, subject, year group, price, rating) and in PHP ("has an open
 * slot in the next 14 days", with the day / time-of-day filters applied to
 * those same slots in the viewer's timezone). Nothing is cached, so a tutor
 * suspended a second ago is gone on the next request.
 *
 * Year group (R33) is a controlled list: with a curriculum chosen, a tutor
 * matches when one of that curriculum's subject rows spans the year group
 * (its lowest year group sorts at or below it, its highest at or above it). A
 * row with no year group on either end (an unmapped legacy row) matches any
 * year group of its curriculum. Without a curriculum the year group is ignored.
 */
class TutorSearch
{
    public const WINDOW_DAYS = 14;

    public function __construct(private readonly SlotCalculator $calculator) {}

    /**
     * @return list<array{profile: TutorProfile, slots: list<Slot>}>
     */
    public function __invoke(TutorSearchCriteria $criteria, string $timezone, ?CarbonInterface $now = null): array
    {
        $tutors = $this->candidates($criteria)->get();

        $slotsByTutor = $this->calculator->forTutors($tutors, $timezone, $now, self::WINDOW_DAYS);

        $results = [];

        foreach ($tutors as $tutor) {
            $slots = array_values(array_filter(
                $slotsByTutor[$tutor->id] ?? [],
                fn (Slot $slot): bool => $this->matchesDayAndTime($slot, $criteria),
            ));

            if ($slots !== []) {
                $results[] = ['profile' => $tutor, 'slots' => $slots];
            }
        }

        return $results;
    }

    /**
     * @return Builder<TutorProfile>
     */
    private function candidates(TutorSearchCriteria $criteria): Builder
    {
        $query = TutorProfile::query()
            ->bookable()
            ->with(['user:id,name,timezone', 'tutorSubjects.curriculum:id,name', 'tutorSubjects.subject:id,name', 'tutorSubjects.levelMin:id,label', 'tutorSubjects.levelMax:id,label']);

        // A year group only means something inside its own curriculum.
        $yearGroup = $criteria->curriculumId !== null && $criteria->yearGroupId !== null
            ? YearGroup::query()->where('curriculum_id', $criteria->curriculumId)->find($criteria->yearGroupId)
            : null;

        if ($criteria->curriculumId !== null || $criteria->subjectId !== null) {
            // One subject row must satisfy every condition at once.
            $query->whereHas('tutorSubjects', function (Builder $subjects) use ($criteria, $yearGroup): void {
                $subjects
                    ->when($criteria->curriculumId !== null, fn (Builder $q) => $q->where('curriculum_id', $criteria->curriculumId))
                    ->when($criteria->subjectId !== null, fn (Builder $q) => $q->where('subject_id', $criteria->subjectId))
                    ->when($yearGroup !== null, function (Builder $q) use ($yearGroup): void {
                        $q->where(fn (Builder $low) => $low->whereNull('level_min_id')
                            ->orWhereHas('levelMin', fn (Builder $min) => $min->where('sort', '<=', $yearGroup->sort)));
                        $q->where(fn (Builder $high) => $high->whereNull('level_max_id')
                            ->orWhereHas('levelMax', fn (Builder $max) => $max->where('sort', '>=', $yearGroup->sort)));
                    });
            });
        }

        if ($criteria->minRate !== null) {
            $query->where('hourly_rate', '>=', $criteria->minRate->toFils());
        }

        if ($criteria->maxRate !== null) {
            $query->where('hourly_rate', '<=', $criteria->maxRate->toFils());
        }

        if ($criteria->minRating !== null) {
            // A tutor with no reviews has no rating: excluded once a minimum is set.
            $query->where('rating_count', '>', 0)->where('rating_avg', '>=', $criteria->minRating);
        }

        return $criteria->sort === TutorSearchCriteria::SORT_PRICE
            ? $query->orderBy('hourly_rate')->orderBy('id')
            : $query->orderByDesc('rating_avg')->orderByDesc('rating_count')->orderBy('id');
    }

    private function matchesDayAndTime(Slot $slot, TutorSearchCriteria $criteria): bool
    {
        if ($criteria->day !== null && $slot->startsAt->dayOfWeek !== $criteria->day) {
            return false;
        }

        if ($criteria->timeOfDay === null) {
            return true;
        }

        $hour = $slot->startsAt->hour;

        return match ($criteria->timeOfDay) {
            TutorSearchCriteria::MORNING => $hour < 12,
            TutorSearchCriteria::AFTERNOON => $hour >= 12 && $hour < 17,
            TutorSearchCriteria::EVENING => $hour >= 17,
            default => true,
        };
    }
}
