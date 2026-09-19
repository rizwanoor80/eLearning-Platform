<?php

namespace App\Services\Search;

use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Services\Scheduling\Slot;
use App\Services\Scheduling\SlotCalculator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tutor search (CP2). Starts from `TutorProfile::bookable()` (invariant #5) —
 * nothing here re-implements what "bookable" means — then narrows in SQL
 * (curriculum, subject, price, rating) and in PHP (year group, and "has an open
 * slot in the next 14 days", with the day / time-of-day filters applied to
 * those same slots in the viewer's timezone). Nothing is cached, so a tutor
 * suspended a second ago is gone on the next request.
 *
 * Year group is a low-fidelity filter: both sides are free text. The first
 * integer of each is compared, only when a curriculum is chosen, only against
 * that curriculum's subject rows; if either side has no integer the tutor
 * stays in.
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
        $tutors = $this->candidates($criteria)->get()->filter(
            fn (TutorProfile $tutor): bool => $this->matchesYearGroup($tutor, $criteria),
        );

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
            ->with(['user:id,name,timezone', 'tutorSubjects.curriculum:id,name', 'tutorSubjects.subject:id,name']);

        if ($criteria->curriculumId !== null || $criteria->subjectId !== null) {
            $query->whereHas('tutorSubjects', function (Builder $subjects) use ($criteria): void {
                $subjects
                    ->when($criteria->curriculumId !== null, fn (Builder $q) => $q->where('curriculum_id', $criteria->curriculumId))
                    ->when($criteria->subjectId !== null, fn (Builder $q) => $q->where('subject_id', $criteria->subjectId));
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

    private function matchesYearGroup(TutorProfile $tutor, TutorSearchCriteria $criteria): bool
    {
        $year = $this->firstInteger($criteria->yearGroup);

        if ($criteria->curriculumId === null || $year === null) {
            return true;
        }

        $rows = $tutor->tutorSubjects->filter(
            fn (TutorSubject $row): bool => $row->curriculum_id === $criteria->curriculumId
                && ($criteria->subjectId === null || $row->subject_id === $criteria->subjectId),
        );

        return $rows->contains(function (TutorSubject $row) use ($year): bool {
            $low = $this->firstInteger($row->level_min);
            $high = $this->firstInteger($row->level_max);

            if ($low === null || $high === null) {
                return true;
            }

            return $year >= min($low, $high) && $year <= max($low, $high);
        });
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

    private function firstInteger(?string $text): ?int
    {
        if ($text === null || preg_match('/\d{1,3}/', $text, $matches) !== 1) {
            return null;
        }

        return (int) $matches[0];
    }
}
