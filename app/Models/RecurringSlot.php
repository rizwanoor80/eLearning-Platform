<?php

namespace App\Models;

use App\Enums\RecurringSlotPauseReason;
use App\Enums\RecurringSlotStatus;
use App\Support\Money;
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
        return $this->belongsTo(Learner::class);
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
