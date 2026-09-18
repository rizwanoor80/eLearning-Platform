<?php

namespace App\Services\Scheduling;

use App\Enums\AvailabilityExceptionType;
use App\Enums\LessonStatus;
use App\Enums\RecurringSlotStatus;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Lesson;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Bookable hourly slots for one tutor (CP2). Deliberately silent about
 * whether the tutor is bookable at all — callers (search, booking, match
 * suggestions) apply `TutorProfile::bookable()` themselves (invariant #5).
 *
 * The core, `calculate()`, is a pure function over pre-loaded collections so it
 * can be unit-tested without a database and batch-loaded by search. Nothing is
 * cached: every input is read at calculation time.
 *
 * Time semantics:
 * - A weekly rule is expanded per local calendar date in the rule's own
 *   timezone (so DST is right); hourly slots step from the rule's start time.
 * - An exception has no timezone column, so it is read in `$exceptionTimezone`
 *   (the tutor's current user timezone; `forTutor()` passes it).
 * - A slot is offered when `starts_at >= now + lead` and `starts_at <= now + max_days`.
 * - Blocking is interval overlap: a lesson at 10:30 removes both 10:00 and 11:00.
 * - An active recurring slot blocks its weekday/time on every date from
 *   `starts_on` until `ends_on`, however far out (PRD §2.6.7).
 */
class SlotCalculator
{
    public const SLOT_MINUTES = 60;

    /**
     * @param  iterable<AvailabilityRule>  $rules
     * @param  iterable<AvailabilityException>  $exceptions
     * @param  iterable<Lesson>  $lessons
     * @param  iterable<RecurringSlot>  $recurringSlots
     * @return list<Slot>
     */
    public function calculate(
        iterable $rules,
        iterable $exceptions,
        iterable $lessons,
        iterable $recurringSlots,
        CarbonInterface $now,
        int $leadHours,
        int $maxDays,
        string $exceptionTimezone,
        string $outputTimezone,
    ): array {
        $now = CarbonImmutable::instance($now)->utc();
        $windowStart = $now->addHours($leadHours);
        $windowEnd = $now->addDays($maxDays);

        /** @var array<int, CarbonImmutable> $candidates keyed by UTC timestamp */
        $candidates = [];
        /** @var list<array{int, int}> $blocked */
        $blocked = [];

        foreach ($rules as $rule) {
            foreach ($this->datesAround($windowStart, $windowEnd, $rule->timezone) as $date) {
                if ($this->dayOfWeek($date, $rule->timezone) === $rule->weekday) {
                    foreach ($this->hourly($date, $rule->start_time, $rule->end_time, $rule->timezone) as $start) {
                        $candidates[$start->getTimestamp()] = $start;
                    }
                }
            }
        }

        foreach ($exceptions as $exception) {
            $date = $exception->date->toDateString();

            if ($exception->type === AvailabilityExceptionType::Extra) {
                foreach ($this->hourly($date, $exception->start_time, $exception->end_time, $exceptionTimezone) as $start) {
                    $candidates[$start->getTimestamp()] = $start;
                }
            } else {
                $blocked[] = $this->interval($date, $exception->start_time, $exception->end_time, $exceptionTimezone);
            }
        }

        foreach ($lessons as $lesson) {
            if ($lesson->status->blocksSlot()) {
                $blocked[] = [$lesson->starts_at->getTimestamp(), $lesson->ends_at->getTimestamp()];
            }
        }

        foreach ($recurringSlots as $slot) {
            if ($slot->status !== RecurringSlotStatus::Active) {
                continue;
            }

            $from = $slot->starts_on->toDateString();
            $until = $slot->ends_on?->toDateString();

            foreach ($this->datesAround($windowStart, $windowEnd, $slot->timezone) as $date) {
                if ($date < $from || ($until !== null && $date > $until)) {
                    continue;
                }

                if ($this->dayOfWeek($date, $slot->timezone) === $slot->weekday) {
                    $start = $this->local($date, $slot->start_time, $slot->timezone);
                    $blocked[] = [$start->getTimestamp(), $start->addMinutes(self::SLOT_MINUTES)->getTimestamp()];
                }
            }
        }

        ksort($candidates);

        $slots = [];
        $length = self::SLOT_MINUTES * 60;

        foreach ($candidates as $timestamp => $start) {
            if ($start < $windowStart || $start > $windowEnd) {
                continue;
            }

            foreach ($blocked as [$blockStart, $blockEnd]) {
                if ($timestamp < $blockEnd && $timestamp + $length > $blockStart) {
                    continue 2;
                }
            }

            $slots[] = new Slot(
                $start->setTimezone($outputTimezone),
                $start->addMinutes(self::SLOT_MINUTES)->setTimezone($outputTimezone),
            );
        }

        return $slots;
    }

    /**
     * Loads one tutor's inputs and settings, then delegates to the pure core.
     *
     * @return list<Slot>
     */
    public function forTutor(TutorProfile $tutor, string $timezone, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? Date::now())->utc();
        $lead = (int) Settings::get('booking_min_lead_hours');
        $maxDays = (int) Settings::get('booking_max_days');
        $from = $now->addHours($lead)->subDays(2);
        $to = $now->addDays($maxDays)->addDays(2);

        return $this->calculate(
            $tutor->availabilityRules()->get(),
            $tutor->availabilityExceptions()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get(),
            Lesson::query()
                ->where('tutor_profile_id', $tutor->id)
                ->whereNotIn('status', LessonStatus::freeingSlotValues())
                ->where('starts_at', '<', $to)
                ->where('ends_at', '>', $from)
                ->get(),
            RecurringSlot::query()
                ->where('tutor_profile_id', $tutor->id)
                ->where('status', RecurringSlotStatus::Active)
                ->get(),
            $now,
            $lead,
            $maxDays,
            $tutor->user->timezone,
            $timezone,
        );
    }

    /**
     * Local calendar dates (Y-m-d) in $timezone covering the window plus a day
     * either side, so a rule near midnight in another zone is never missed.
     *
     * @return list<string>
     */
    private function datesAround(CarbonImmutable $windowStart, CarbonImmutable $windowEnd, string $timezone): array
    {
        $day = $windowStart->setTimezone($timezone)->startOfDay()->subDay();
        $last = $windowEnd->setTimezone($timezone)->startOfDay()->addDay();
        $dates = [];

        while ($day <= $last) {
            $dates[] = $day->toDateString();
            $day = $day->addDay();
        }

        return $dates;
    }

    private function dayOfWeek(string $date, string $timezone): int
    {
        return CarbonImmutable::parse($date, $timezone)->dayOfWeek;
    }

    private function local(string $date, string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.substr($time, 0, 5), $timezone)->utc();
    }

    /**
     * @return array{int, int}
     */
    private function interval(string $date, string $start, string $end, string $timezone): array
    {
        return [$this->local($date, $start, $timezone)->getTimestamp(), $this->local($date, $end, $timezone)->getTimestamp()];
    }

    /**
     * Whole-hour slots stepping from $start while a full slot still fits before $end.
     *
     * @return list<CarbonImmutable>
     */
    private function hourly(string $date, string $start, string $end, string $timezone): array
    {
        $cursor = $this->local($date, $start, $timezone);
        $limit = $this->local($date, $end, $timezone);
        $starts = [];

        while ($cursor->addMinutes(self::SLOT_MINUTES) <= $limit) {
            $starts[] = $cursor;
            $cursor = $cursor->addMinutes(self::SLOT_MINUTES);
        }

        return $starts;
    }
}
