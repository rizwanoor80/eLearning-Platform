<?php

namespace App\Services\Scheduling;

use App\Models\TutorProfile;
use App\Support\Facades\Settings;
use Carbon\CarbonInterface;

/**
 * How far ahead a parent must book a given tutor (R179, ADR-025). The one place the answer is
 * computed: slot generation, `BookLesson`'s slot check, the weekly-slot first-lesson check, search,
 * the profile page and the tutor's own form all call `for()` — nothing else reads
 * `min_lead_hours` or `booking_min_lead_hours` directly.
 *
 * A tutor's own choice (`min_lead_hours`, null when unset) falls back to the platform default
 * (`booking_min_lead_hours`), and is then resolved against the admin's allowed list, so a value the
 * admin later removes — or `0` while `allow_immediate_booking` is off — can never be reached.
 * Resolution is "the nearest allowed value that is not shorter than the choice, else the longest
 * allowed value": a tutor is never made available sooner than they asked for.
 */
class BookingLeadTime
{
    /** The longest lead the admin can offer: a week. */
    public const MAX_HOURS = 168;

    public function allowsImmediate(): bool
    {
        return (bool) Settings::get('allow_immediate_booking');
    }

    /**
     * The values a tutor may choose from, ascending. Never empty.
     *
     * @return list<int>
     */
    public function options(): array
    {
        $raw = Settings::get('lead_time_options');
        $values = [];

        foreach (is_array($raw) ? $raw : [] as $value) {
            if (is_numeric($value) && (int) $value >= 0 && (int) $value <= self::MAX_HOURS) {
                $values[(int) $value] = (int) $value;
            }
        }

        if (! $this->allowsImmediate()) {
            unset($values[0]);
        }

        if ($values === []) {
            $values[] = max((int) Settings::get('booking_min_lead_hours'), $this->allowsImmediate() ? 0 : 1);
        }

        sort($values);

        return $values;
    }

    /**
     * The tutor's effective lead time in whole hours.
     */
    public function for(TutorProfile $tutor): int
    {
        return $this->resolve($tutor->min_lead_hours ?? (int) Settings::get('booking_min_lead_hours'));
    }

    public function resolve(int $chosen): int
    {
        $options = $this->options();

        foreach ($options as $option) {
            if ($option >= $chosen) {
                return $option;
            }
        }

        return $options[count($options) - 1];
    }

    /**
     * Minute-precision rule (ADR-025): a start is bookable when it is at or after `now + lead`, and —
     * which only matters at lead 0 — strictly after `now`.
     */
    public function accepts(CarbonInterface $start, CarbonInterface $now, int $leadHours): bool
    {
        return $start->greaterThan($now) && $start->greaterThanOrEqualTo($now->copy()->addHours($leadHours));
    }

    /**
     * "Book right away" / "Book from 4 hours ahead" — the same words in search, on the profile
     * and in the tutor's own form.
     */
    public function label(int $hours): string
    {
        if ($hours === 0) {
            return __('Book right away');
        }

        return $hours === 1 ? __('Book from 1 hour ahead') : __('Book from :hours hours ahead', ['hours' => $hours]);
    }
}
