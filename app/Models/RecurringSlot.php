<?php

namespace App\Models;

use App\Enums\RecurringSlotPauseReason;
use App\Enums\RecurringSlotStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\RecurringSlotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A standing weekly reservation: one learner, one tutor, one weekday and time in the
 * tutor's timezone (DATA_MODEL v1.5). Each generated lesson is charged individually — it is
 * not a subscription and holds no credit. `price` is agreed once and frozen here (R95).
 *
 * @property int $id
 * @property int $learner_id
 * @property int $tutor_profile_id
 * @property int $curriculum_id
 * @property int $subject_id
 * @property int $weekday
 * @property string $start_time
 * @property string $timezone
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property Money $price
 * @property RecurringSlotStatus $status
 * @property RecurringSlotPauseReason|null $paused_reason
 * @property int|null $ended_by_user_id
 * @property Carbon|null $ended_at
 * @property Carbon|null $end_effective_on
 * @property int $consecutive_charge_failures
 * @property Carbon $generated_until
 * @property int $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RecurringSlot extends Model
{
    /** @use HasFactory<RecurringSlotFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'price' => Money::class,
            'status' => RecurringSlotStatus::class,
            'paused_reason' => RecurringSlotPauseReason::class,
            'ended_at' => 'datetime',
            'end_effective_on' => 'date',
            'consecutive_charge_failures' => 'integer',
            'generated_until' => 'date',
        ];
    }

    /**
     * The statuses in which a slot still holds its weekday/time (`ended` frees it).
     *
     * @return list<RecurringSlotStatus>
     */
    public static function holdingStatuses(): array
    {
        return [RecurringSlotStatus::Active, RecurringSlotStatus::Paused];
    }

    /**
     * The last date (inclusive) on which the slot still occurs: the earlier of the parent's
     * optional `ends_on` and a tutor-ended slot's `end_effective_on`; null = open-ended.
     */
    public function effectiveEndDate(): ?CarbonInterface
    {
        $ends = array_values(array_filter([$this->ends_on, $this->end_effective_on]));

        if ($ends === []) {
            return null;
        }

        return count($ends) === 1 || $ends[0]->lessThanOrEqualTo($ends[1]) ? $ends[0] : $ends[1];
    }

    /**
     * "Tuesday 17:00 (Asia/Dubai)": the slot in its own (the tutor's) timezone. A parent in another
     * zone reads `nextOccurrenceAfter()` instead, because the weekday itself can differ there.
     */
    public function scheduleLabel(): string
    {
        // 2024-01-07 was a Sunday, so day-of-week 0 = Sunday lines up with `weekday`.
        $day = CarbonImmutable::create(2024, 1, 7 + $this->weekday)->format('l');

        return sprintf('%s %s (%s)', $day, substr($this->start_time, 0, 5), $this->timezone);
    }

    /**
     * The first occurrence strictly after `$after`, honouring `starts_on` and the effective end;
     * null once the slot has no further occurrence. Each occurrence is built on its own local date,
     * so it is DST-correct (R97). Callers convert to the viewer's timezone at the edge.
     */
    public function nextOccurrenceAfter(CarbonInterface $after): ?CarbonImmutable
    {
        $date = CarbonImmutable::instance($after)->setTimezone($this->timezone)->startOfDay();
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $this->starts_on->toDateString(), $this->timezone);

        if ($date->lessThan($first)) {
            $date = $first;
        }

        $end = $this->effectiveEndDate();

        // At most two passes are needed to land on the weekday and get past `$after` on the same day.
        for ($i = 0; $i < 15; $i++) {
            if ($end !== null && $date->toDateString() > $end->toDateString()) {
                return null;
            }

            if ($date->dayOfWeek === $this->weekday) {
                $start = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $date->toDateString().' '.$this->start_time, $this->timezone)->utc();

                if ($start->greaterThan($after)) {
                    return $start;
                }
            }

            $date = $date->addDay();
        }

        return null;
    }

    /**
     * @return BelongsTo<TutorProfile, $this>
     */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }

    /**
     * @return BelongsTo<Learner, $this>
     */
    public function learner(): BelongsTo
    {
        // A slot outlives a removed learner until it is ended, and its emails and the parent's list
        // still name them; callers that must not act for a removed learner check `trashed()`.
        return $this->belongsTo(Learner::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Curriculum, $this>
     */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /**
     * @return HasMany<RecurringSlotSkip, $this>
     */
    public function skips(): HasMany
    {
        return $this->hasMany(RecurringSlotSkip::class);
    }
}
