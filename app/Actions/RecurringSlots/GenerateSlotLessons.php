<?php

namespace App\Actions\RecurringSlots;

use App\Actions\RecordAuditLog;
use App\Enums\AvailabilityExceptionType;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\RecurringSlotSkipReason;
use App\Enums\RecurringSlotStatus;
use App\Models\AvailabilityException;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\RecurringSlot;
use App\Models\RecurringSlotSkip;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Lessons\LessonStateMachine;
use App\Services\Scheduling\SlotCalculator;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * One weekly slot's daily generation pass (R98), in one transaction with the slot row locked.
 *
 * 1. A slot whose end date has passed is flipped to `ended` — this is where a tutor's notice period
 *    (4b) finishes — and nothing else happens to it.
 * 2. An `active` slot is extended from the day after `generated_until` (never earlier than today)
 *    to today + `recurring_horizon_weeks`, or its end date if that comes first. Each occurrence
 *    becomes a `reserved` `regular` lesson through the state machine's creation entry point, or a
 *    `recurring_slot_skips` row when the tutor cannot take it. Paused and ended slots do nothing.
 *
 * Idempotent (invariant 14): `generated_until` only moves forward, an occurrence that already has a
 * lesson is left alone, skips are `insertOrIgnore`, and `lessons_recurring_slot_starts_at_unique` is
 * the database backstop. Price is the slot's (R95); commission, cancel window and grace minutes are
 * frozen from settings here, when the lesson row is created (invariants 6 and 11). No payment row and
 * no ledger entry — money moves in `recurring:charge` (4e).
 */
class GenerateSlotLessons
{
    public function __construct(private readonly SlotCalculator $calculator, private readonly RecordAuditLog $audit) {}

    /**
     * @return array{created: int, skipped: int, ended: bool}
     */
    public function __invoke(RecurringSlot $slot): array
    {
        return DB::transaction(function () use ($slot): array {
            $locked = RecurringSlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();
            $timezone = $locked->timezone;
            $now = CarbonImmutable::now();
            $today = $now->setTimezone($timezone)->startOfDay();

            if ($this->hasFinished($locked, $today)) {
                $this->end($locked);

                return ['created' => 0, 'skipped' => 0, 'ended' => true];
            }

            if ($locked->status !== RecurringSlotStatus::Active) {
                return ['created' => 0, 'skipped' => 0, 'ended' => false];
            }

            $learner = Learner::query()->withTrashed()->find($locked->learner_id);

            // Nobody left to book for (a removed learner, or an anonymised parent account): nothing is reserved.
            if ($learner === null || $learner->trashed() || $learner->account === null || $learner->account->trashed()) {
                return ['created' => 0, 'skipped' => 0, 'ended' => false];
            }

            // Slot-local `Y-m-d` strings throughout: the date-cast columns load as UTC midnight, so
            // comparing them with local-midnight instants would drop days east of UTC.
            $from = max(
                $today->toDateString(),
                $locked->generated_until->addDay()->toDateString(),
                $locked->starts_on->toDateString(),
            );
            $last = $today->addWeeks((int) Settings::get('recurring_horizon_weeks'))->toDateString();
            $endsOn = $locked->effectiveEndDate();

            if ($endsOn !== null) {
                $last = min($last, $endsOn->toDateString());
            }

            if ($from > $last) {
                return ['created' => 0, 'skipped' => 0, 'ended' => false];
            }

            $counts = $this->walk($locked, $learner, $from, $last, $now);

            // Never backwards: a permit boundary can leave the walk short of where an earlier run got to.
            $locked->forceFill(['generated_until' => max($locked->generated_until->toDateString(), $counts['until'])])->save();

            return ['created' => $counts['created'], 'skipped' => $counts['skipped'], 'ended' => false];
        });
    }

    private function hasFinished(RecurringSlot $slot, CarbonImmutable $today): bool
    {
        if (! in_array($slot->status, RecurringSlot::holdingStatuses(), true)) {
            return false;
        }

        $endsOn = $slot->effectiveEndDate();

        return $endsOn !== null && $endsOn->toDateString() < $today->toDateString();
    }

    private function end(RecurringSlot $slot): void
    {
        $before = ['status' => $slot->status->value, 'end_effective_on' => $slot->end_effective_on?->toDateString()];

        // `ended_by_user_id` / `ended_at` were set by `EndRecurringSlot` when a tutor gave notice; only
        // an `ends_on` that simply ran out still needs its timestamp.
        $slot->forceFill(['status' => RecurringSlotStatus::Ended, 'ended_at' => $slot->ended_at ?? now()])->save();

        ($this->audit)(null, 'recurring_slot.ended', $slot, $before, ['status' => RecurringSlotStatus::Ended->value, 'reason' => 'end date reached']);
    }

    /**
     * @return array{created: int, skipped: int, until: string} `until` is the last local date fully covered
     */
    private function walk(RecurringSlot $slot, Learner $learner, string $from, string $last, CarbonImmutable $now): array
    {
        $tutor = TutorProfile::query()->findOrFail($slot->tutor_profile_id);
        $bookable = TutorProfile::query()->bookable()->whereKey($tutor->id)->exists();
        $permitCutoff = $tutor->permit_expires_at === null ? null : CarbonImmutable::instance($tutor->permit_expires_at)->utc()->startOfDay();
        // Exceptions have no timezone column: they are read in the tutor's own, as SlotCalculator does.
        $tutorTimezone = (string) User::withTrashed()->whereKey($tutor->user_id)->value('timezone');

        // Read once per run, so a settings edit cannot straddle one slot's occurrences.
        $commissionPct = (int) Settings::get('commission_pct');
        $policy = [
            'cancel_window_hours' => (int) Settings::get('cancel_window_hours'),
            'student_grace_min' => (int) Settings::get('student_grace_min'),
            'tutor_grace_min' => (int) Settings::get('tutor_grace_min'),
        ];
        $chargeLeadHours = (int) Settings::get('recurring_charge_lead_hours');

        $starts = [];
        $until = $last;

        for ($date = CarbonImmutable::parse($from, $slot->timezone); $date->toDateString() <= $last; $date = $date->addDay()) {
            if ($date->dayOfWeek !== $slot->weekday) {
                continue;
            }

            $start = $this->calculator->local($date->toDateString(), $slot->start_time, $slot->timezone);

            // A permit that will lapse inside the horizon is not a skip yet — the tutor may renew before
            // then. Generation stops the day before it and picks up again on a later run; the skips
            // (and the email) are written only once the tutor really is unbookable. An occurrence less
            // than a day away is not held: no further daily run would see it before it starts, and
            // dropping it silently would lose the date without a skip or an email — it is skipped now.
            if ($bookable && $permitCutoff !== null && $start >= $permitCutoff && $start > $now->addDay()) {
                $until = $date->subDay()->toDateString();

                break;
            }

            if ($start > $now) {
                $starts[] = $start;
            }
        }

        if ($starts === []) {
            return ['created' => 0, 'skipped' => 0, 'until' => $until];
        }

        $rangeStart = $starts[0];
        $rangeEnd = end($starts)->addMinutes(SlotCalculator::SLOT_MINUTES);

        /** @var array<int, true> $existing UTC timestamps that already have a lesson from this slot */
        $existing = Lesson::query()
            ->where('recurring_slot_id', $slot->id)
            ->whereBetween('starts_at', [$rangeStart, $rangeEnd])
            ->where(fn ($query) => $query->whereNull('cancel_reason')->orWhere('cancel_reason', '!=', LessonCancelReason::SlotPaused->value))
            ->get(['starts_at'])
            ->mapWithKeys(fn (Lesson $lesson): array => [$lesson->starts_at->getTimestamp() => true])
            ->all();

        // A recorded skip is final (R98: the admin decides), so a resume that re-walks the date must not
        // turn it into a lesson after the parent was told it was skipped.
        foreach (RecurringSlotSkip::query()->where('recurring_slot_id', $slot->id)->whereBetween('starts_at', [$rangeStart, $rangeEnd])->get(['starts_at']) as $skip) {
            $existing[$skip->starts_at->getTimestamp()] = true;
        }

        $busy = Lesson::query()
            ->where('tutor_profile_id', $tutor->id)
            ->whereNotIn('status', LessonStatus::freeingSlotValues())
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->get(['starts_at', 'ends_at']);

        $blocked = AvailabilityException::query()
            ->where('tutor_profile_id', $tutor->id)
            ->where('type', AvailabilityExceptionType::Blocked)
            ->whereBetween('date', [CarbonImmutable::parse($from)->subDays(2)->toDateString(), CarbonImmutable::parse($last)->addDays(2)->toDateString()])
            ->get()
            ->map(fn (AvailabilityException $exception): array => [
                $this->calculator->local($exception->date->toDateString(), $exception->start_time, $tutorTimezone)->getTimestamp(),
                $this->calculator->local($exception->date->toDateString(), $exception->end_time, $tutorTimezone)->getTimestamp(),
            ]);

        $created = 0;
        $skipped = 0;

        foreach ($starts as $start) {
            if (isset($existing[$start->getTimestamp()])) {
                continue;
            }

            $end = $start->addMinutes(SlotCalculator::SLOT_MINUTES);
            $reason = null;

            if (! $bookable || ($permitCutoff !== null && $start >= $permitCutoff)) {
                $reason = RecurringSlotSkipReason::TutorUnavailable;
            } elseif ($blocked->contains(fn (array $b): bool => $start->getTimestamp() < $b[1] && $end->getTimestamp() > $b[0])) {
                $reason = RecurringSlotSkipReason::TutorBlocked;
            } elseif ($busy->contains(fn (Lesson $lesson): bool => $start < $lesson->ends_at && $end > $lesson->starts_at)) {
                $reason = RecurringSlotSkipReason::LessonCollision;
            }

            if ($reason === null) {
                $reason = $this->open($slot, $learner, $start, $end, $commissionPct, $policy, $chargeLeadHours, $created);
            }

            if ($reason !== null) {
                $skipped += $this->skip($slot, $start, $reason);
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'until' => $until];
    }

    /**
     * Creates the occurrence's lesson. Returns the skip reason if the database refused it for a
     * collision (a race the read-side checks could not see), null on success or when the occurrence
     * already has a lesson.
     *
     * @param  array{cancel_window_hours: int, student_grace_min: int, tutor_grace_min: int}  $policy
     */
    private function open(RecurringSlot $slot, Learner $learner, CarbonImmutable $start, CarbonImmutable $end, int $commissionPct, array $policy, int $chargeLeadHours, int &$created): ?RecurringSlotSkipReason
    {
        $split = $slot->price->splitCommission($commissionPct);

        try {
            // A nested transaction is a savepoint: a refused insert rolls back only itself.
            LessonStateMachine::open([
                'type' => LessonType::Regular,
                'recurring_slot_id' => $slot->id,
                'learner_id' => $learner->id,
                'tutor_profile_id' => $slot->tutor_profile_id,
                'booked_by_user_id' => $learner->account_user_id,
                'curriculum_id' => $slot->curriculum_id,
                'subject_id' => $slot->subject_id,
                'starts_at' => $start,
                'ends_at' => $end,
                'duration_minutes' => SlotCalculator::SLOT_MINUTES,
                'price' => $slot->price,
                'commission_pct' => $commissionPct,
                'commission_amount' => $split['commission'],
                'tutor_amount' => $split['tutor'],
                ...$policy,
                'next_charge_at' => $start->subHours($chargeLeadHours),
            ], LessonStatus::Reserved);
        } catch (QueryException $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'lessons_recurring_slot_starts_at_unique')) {
                return null;
            }

            if (str_contains($message, 'lessons_tutor_no_overlap') || str_contains($message, 'lessons_tutor_slot_unique')) {
                return RecurringSlotSkipReason::LessonCollision;
            }

            throw $e;
        }

        $created++;

        return null;
    }

    private function skip(RecurringSlot $slot, CarbonImmutable $start, RecurringSlotSkipReason $reason): int
    {
        return DB::table('recurring_slot_skips')->insertOrIgnore([
            'recurring_slot_id' => $slot->id,
            'starts_at' => $start->utc()->toDateTimeString(),
            'reason' => $reason->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
