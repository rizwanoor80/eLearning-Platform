<?php

namespace App\Actions\RecurringSlots;

use App\Actions\RecordAuditLog;
use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\PaymentMethodStatus;
use App\Enums\RecurringSlotStatus;
use App\Exceptions\RecurringSlotException;
use App\Models\AvailabilityRule;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PaymentMethod;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Services\Scheduling\SlotCalculator;
use App\Services\Tutors\TutorRateBands;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sets up a weekly slot (R95/R96): one learner, one tutor, one weekday and hour in the tutor's
 * timezone, at the tutor's current rate, which is frozen on the slot. The parent must already
 * have a completed trial with this tutor; an admin may override that with a typed reason, which
 * is audited — every other check, the saved card included, still runs on the override path.
 *
 * Availability, lead time and collisions are checked here so a slot is never created that its
 * first lesson could not be generated for; the DB's `recurring_slots_live_unique` is the final
 * word on two people racing for the same weekday and hour.
 */
class CreateRecurringSlot
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * @param  array{curriculum_id: int, subject_id: int, weekday: int, start_time: string, starts_on: string, ends_on?: string|null}  $data  `start_time` is `H:i` in the tutor's timezone; dates are `Y-m-d`
     * @param  string|null  $overrideReason  admin-only: skips the trial prerequisite; must be non-blank
     *
     * @throws RecurringSlotException
     */
    public function __invoke(User $actor, Learner $learner, TutorProfile $tutor, array $data, ?string $overrideReason = null): RecurringSlot
    {
        if ($learner->trashed()) {
            throw new RecurringSlotException('A weekly slot cannot be set up for a removed learner.');
        }

        if (! Gate::forUser($actor)->allows('createFor', [RecurringSlot::class, $learner])) {
            throw new RecurringSlotException('You cannot set up a weekly slot for this learner.');
        }

        $override = $overrideReason !== null;

        if ($override && (! Gate::forUser($actor)->allows('create', RecurringSlot::class) || trim($overrideReason) === '')) {
            throw new RecurringSlotException('Only an admin can skip the trial requirement, and only with a reason.');
        }

        $freshTutor = TutorProfile::query()->bookable()->whereKey($tutor->id)->first();

        if ($freshTutor === null) {
            throw new RecurringSlotException('This tutor is not currently bookable.');
        }

        if (($problem = app(TutorRateBands::class)->problemWithRate($freshTutor)) !== null) {
            throw new RecurringSlotException("This tutor cannot be booked right now: {$problem}.");
        }

        $price = $freshTutor->hourly_rate;

        if ($price === null || $price->isZero() || $price->isNegative()) {
            throw new RecurringSlotException('This tutor has no valid hourly rate.');
        }

        $teachesSubject = TutorSubject::query()
            ->where('tutor_profile_id', $freshTutor->id)
            ->where('curriculum_id', $data['curriculum_id'])
            ->where('subject_id', $data['subject_id'])
            ->exists();

        if (! $teachesSubject) {
            throw new RecurringSlotException('This tutor does not teach the requested curriculum and subject.');
        }

        if (! $override && ! $this->hasCompletedTrial($learner, $freshTutor)) {
            throw new RecurringSlotException('A weekly slot can be set up once the trial lesson with this tutor has been completed.');
        }

        $this->requireUsableCard($learner);

        $weekday = (int) $data['weekday'];
        $time = $this->normaliseTime($data['start_time']);
        $rule = $this->coveringRule($freshTutor, $weekday, $time);
        $timezone = $rule->timezone;

        $startsOn = CarbonImmutable::createFromFormat('!Y-m-d', $data['starts_on'], $timezone);
        $endsOn = isset($data['ends_on']) && $data['ends_on'] !== ''
            ? CarbonImmutable::createFromFormat('!Y-m-d', $data['ends_on'], $timezone)
            : null;

        if ($endsOn !== null && $endsOn->lessThan($startsOn)) {
            throw new RecurringSlotException('The end date cannot be before the start date.');
        }

        $first = $this->firstOccurrence($startsOn, $weekday, $time, $timezone);

        if ($endsOn !== null && $first->setTimezone($timezone)->startOfDay()->greaterThan($endsOn)) {
            throw new RecurringSlotException('The end date falls before the first lesson of this weekly slot.');
        }

        $lead = (int) Settings::get('booking_min_lead_hours');

        if ($first->lessThan(now()->addHours($lead))) {
            throw new RecurringSlotException("The first lesson must be at least {$lead} hours from now.");
        }

        if ($this->collides($freshTutor, $first, $weekday, $time, $timezone, $endsOn)) {
            throw new RecurringSlotException('This time overlaps a lesson the tutor already has booked.');
        }

        try {
            return DB::transaction(function () use ($actor, $learner, $freshTutor, $data, $weekday, $time, $timezone, $startsOn, $endsOn, $price, $override, $overrideReason): RecurringSlot {
                $slot = RecurringSlot::query()->create([
                    'learner_id' => $learner->id,
                    'tutor_profile_id' => $freshTutor->id,
                    'curriculum_id' => $data['curriculum_id'],
                    'subject_id' => $data['subject_id'],
                    'weekday' => $weekday,
                    'start_time' => $time,
                    'timezone' => $timezone,
                    'starts_on' => $startsOn->toDateString(),
                    'ends_on' => $endsOn?->toDateString(),
                    'price' => $price,
                    'status' => RecurringSlotStatus::Active,
                    'consecutive_charge_failures' => 0,
                    'generated_until' => $startsOn->subDay()->toDateString(),
                    'created_by_user_id' => $actor->id,
                ]);

                ($this->audit)($actor, 'recurring_slot.created', $slot, null, [
                    'learner_id' => $learner->id,
                    'tutor_profile_id' => $freshTutor->id,
                    'weekday' => $weekday,
                    'start_time' => $time,
                    'timezone' => $timezone,
                    'price_fils' => $price->toFils(),
                    'trial_override' => $override,
                    'override_reason' => $override ? trim((string) $overrideReason) : null,
                ]);

                return $slot;
            });
        } catch (QueryException $e) {
            // Outside the transaction: a failed statement aborts a PostgreSQL transaction, so the
            // constraint is translated only after it has rolled back.
            if (str_contains($e->getMessage(), 'recurring_slots_live_unique')) {
                throw new RecurringSlotException('This tutor already has a weekly slot at that day and time.', previous: $e);
            }

            throw $e;
        }
    }

    private function hasCompletedTrial(Learner $learner, TutorProfile $tutor): bool
    {
        return Lesson::query()
            ->where('learner_id', $learner->id)
            ->where('tutor_profile_id', $tutor->id)
            ->where('type', LessonType::Trial)
            ->whereIn('status', [LessonStatus::Completed, LessonStatus::CompletedReported, LessonStatus::Settled])
            ->exists();
    }

    private function requireUsableCard(Learner $learner): void
    {
        $card = PaymentMethod::query()
            ->where('account_user_id', $learner->account_user_id)
            ->where('status', PaymentMethodStatus::Active)
            ->first();

        // A card is good through the last day of its expiry month.
        $lastDay = $card === null
            ? null
            : CarbonImmutable::create($card->exp_year, $card->exp_month, 1)->endOfMonth()->startOfDay();

        if ($card === null || $lastDay->lessThan(now()->startOfDay())) {
            throw new RecurringSlotException('A saved, unexpired card is needed before a weekly slot can be set up.');
        }
    }

    private function normaliseTime(string $time): string
    {
        if (preg_match('/^([01]\d|2[0-3]):00(:00)?$/', $time) !== 1) {
            throw new RecurringSlotException('A weekly slot starts on the hour.');
        }

        return substr($time, 0, 5).':00';
    }

    private function coveringRule(TutorProfile $tutor, int $weekday, string $time): AvailabilityRule
    {
        if ($weekday < 0 || $weekday > 6) {
            throw new RecurringSlotException('The weekday is not valid.');
        }

        $endOfLesson = CarbonImmutable::createFromFormat('!H:i:s', $time)->addMinutes(SlotCalculator::SLOT_MINUTES)->format('H:i:s');

        $rule = AvailabilityRule::query()
            ->where('tutor_profile_id', $tutor->id)
            ->where('weekday', $weekday)
            ->where('start_time', '<=', $time)
            ->where('end_time', '>=', $endOfLesson)
            ->first();

        if ($rule === null || $endOfLesson <= $time) {
            throw new RecurringSlotException('The tutor is not available at that day and time.');
        }

        return $rule;
    }

    private function firstOccurrence(CarbonImmutable $startsOn, int $weekday, string $time, string $timezone): CarbonImmutable
    {
        $date = $startsOn;

        while ($date->dayOfWeek !== $weekday) {
            $date = $date->addDay();
        }

        return CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $date->toDateString().' '.$time, $timezone)->utc();
    }

    /**
     * Whether any future lesson of the tutor that still holds its slot overlaps an occurrence of
     * the new weekly slot. Each lesson is checked against the local dates either side of its own,
     * so a lesson near midnight in another zone is never missed.
     */
    private function collides(TutorProfile $tutor, CarbonImmutable $first, int $weekday, string $time, string $timezone, ?CarbonImmutable $endsOn): bool
    {
        $lessons = Lesson::query()
            ->where('tutor_profile_id', $tutor->id)
            ->whereNotIn('status', LessonStatus::freeingSlotValues())
            ->where('ends_at', '>', $first)
            ->get(['starts_at', 'ends_at']);

        foreach ($lessons as $lesson) {
            $localStart = CarbonImmutable::instance($lesson->starts_at)->setTimezone($timezone)->startOfDay();

            foreach ([-1, 0, 1] as $offset) {
                $date = $localStart->addDays($offset);

                if ($date->dayOfWeek !== $weekday || ($endsOn !== null && $date->greaterThan($endsOn))) {
                    continue;
                }

                $start = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $date->toDateString().' '.$time, $timezone)->utc();
                $end = $start->addMinutes(SlotCalculator::SLOT_MINUTES);

                if ($start->greaterThanOrEqualTo($first) && $lesson->starts_at < $end && $lesson->ends_at > $start) {
                    return true;
                }
            }
        }

        return false;
    }
}
