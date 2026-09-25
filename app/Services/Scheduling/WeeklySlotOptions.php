<?php

namespace App\Services\Scheduling;

use App\Models\AvailabilityRule;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;

/**
 * The weekday-and-hour choices a parent is offered when setting up a weekly slot with a tutor
 * (R96): every whole hour that fits a 60-minute lesson inside one of the tutor's availability
 * rules, minus the hours another live slot of theirs already holds. Rules are in the tutor's
 * timezone, so `label` is too; `next` is the first lesson in the viewer's own timezone, because
 * the weekday can differ there. `CreateRecurringSlot` still has the last word (lead time,
 * existing lessons, a race for the same hour).
 */
class WeeklySlotOptions
{
    /**
     * @return list<array{weekday: int, start_time: string, value: string, label: string, next: string|null, first_on: string|null}>
     */
    public function forTutor(TutorProfile $tutor, string $viewerTimezone): array
    {
        $held = RecurringSlot::query()
            ->where('tutor_profile_id', $tutor->id)
            ->whereIn('status', RecurringSlot::holdingStatuses())
            ->get(['weekday', 'start_time'])
            ->map(fn (RecurringSlot $slot): string => $slot->weekday.'|'.substr($slot->start_time, 0, 5))
            ->all();

        $after = now()->addHours((int) Settings::get('booking_min_lead_hours'));
        $options = [];

        $rules = AvailabilityRule::query()
            ->where('tutor_profile_id', $tutor->id)
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();

        foreach ($rules as $rule) {
            foreach ($this->hourStarts($rule) as $time) {
                $value = $rule->weekday.'|'.$time;

                if (in_array($value, $held, true) || isset($options[$value])) {
                    continue;
                }

                $slot = new RecurringSlot([
                    'weekday' => $rule->weekday,
                    'start_time' => $time.':00',
                    'timezone' => $rule->timezone,
                    'starts_on' => $after->setTimezone($rule->timezone)->toDateString(),
                ]);

                $next = $slot->nextOccurrenceAfter($after);

                $options[$value] = [
                    'weekday' => $rule->weekday,
                    'start_time' => $time,
                    'value' => $value,
                    'label' => $slot->scheduleLabel(),
                    'next' => $next?->setTimezone($viewerTimezone)->format('D, j M Y, g:i A'),
                    // The local date (in the tutor's zone) of that first lesson: the `starts_on` the action reads.
                    'first_on' => $next?->setTimezone($rule->timezone)->toDateString(),
                ];
            }
        }

        return array_values($options);
    }

    /**
     * @return list<string> `H:i` whole-hour starts whose 60 minutes end by the rule's end
     */
    private function hourStarts(AvailabilityRule $rule): array
    {
        $start = CarbonImmutable::createFromFormat('!H:i:s', $rule->start_time);
        $end = CarbonImmutable::createFromFormat('!H:i:s', $rule->end_time);

        if ($start->minute !== 0 || $start->second !== 0) {
            $start = $start->addHour()->startOfHour();
        }

        $starts = [];

        for ($t = $start; $t->addMinutes(SlotCalculator::SLOT_MINUTES)->lessThanOrEqualTo($end); $t = $t->addHour()) {
            $starts[] = $t->format('H:i');
        }

        return $starts;
    }
}
