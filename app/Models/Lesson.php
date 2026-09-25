<?php

namespace App\Models;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Support\Money;
use Closure;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One lesson: exactly one learner and one tutor (invariant #10). `status` changes
 * only through `LessonStateMachine` (invariant #2) — a `saving` hook refuses any
 * other write to it. The price and the policy values are frozen on the row at
 * creation and never re-read from settings (invariants #6 and #11).
 *
 * @property int $id
 * @property LessonType $type
 * @property int|null $recurring_slot_id
 * @property int $learner_id
 * @property int $tutor_profile_id
 * @property int $booked_by_user_id
 * @property int $curriculum_id
 * @property int $subject_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $duration_minutes
 * @property Money $price
 * @property int $commission_pct
 * @property Money $commission_amount
 * @property Money $tutor_amount
 * @property int $cancel_window_hours
 * @property int $student_grace_min
 * @property int $tutor_grace_min
 * @property LessonStatus $status
 * @property int|null $payment_method_id
 * @property int $charge_attempts
 * @property Carbon|null $next_charge_at
 * @property string|null $room_provider
 * @property string|null $room_id
 * @property string|null $tutor_join_url
 * @property string|null $learner_join_url
 * @property Carbon|null $room_created_at
 * @property Carbon|null $tutor_joined_at
 * @property Carbon|null $learner_joined_at
 * @property Carbon|null $room_closed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancel_reason
 * @property Carbon|null $report_due_at
 * @property Carbon|null $escrow_released_at
 * @property Carbon|null $reminder_24h_sent_at
 * @property Carbon|null $reminder_1h_sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    /**
     * How many `allowingStatusWrites()` scopes are open. Only the state machine
     * (and the factory, so tests can build any state) opens one.
     */
    private static int $statusWriters = 0;

    // `status` is deliberately not mass-assignable: it changes only through
    // LessonStateMachine, which `forceFill`s it at creation and on every edge.
    protected $fillable = ['tutor_profile_id', 'starts_at', 'ends_at'];

    /**
     * Runs `$callback` with writes to `status` permitted. Reserved for
     * `LessonStateMachine` and `LessonFactory`; a test scans `app/` for any
     * other caller.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function allowingStatusWrites(Closure $callback): mixed
    {
        self::$statusWriters++;

        try {
            return $callback();
        } finally {
            self::$statusWriters--;
        }
    }

    protected static function booted(): void
    {
        static::saving(function (Lesson $lesson): void {
            if ($lesson->isDirty('status') && self::$statusWriters === 0) {
                throw new LogicException('lessons.status changes only through LessonStateMachine.');
            }
        });
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'type' => LessonType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'price' => Money::class,
            'commission_amount' => Money::class,
            'tutor_amount' => Money::class,
            'status' => LessonStatus::class,
            'next_charge_at' => 'datetime',
            'room_created_at' => 'datetime',
            'tutor_joined_at' => 'datetime',
            'learner_joined_at' => 'datetime',
            'room_closed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'report_due_at' => 'datetime',
            'escrow_released_at' => 'datetime',
            'reminder_24h_sent_at' => 'datetime',
            'reminder_1h_sent_at' => 'datetime',
        ];
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
        return $this->belongsTo(Learner::class)->withTrashed();
    }

    /**
     * Includes an anonymised (soft-deleted) user (R54) so a past lesson can
     * still say who booked it.
     *
     * @return BelongsTo<User, $this>
     */
    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by_user_id')->withTrashed();
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
     * The weekly slot that generated this lesson, if any.
     *
     * @return BelongsTo<RecurringSlot, $this>
     */
    public function recurringSlot(): BelongsTo
    {
        return $this->belongsTo(RecurringSlot::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * The lesson's live payment: its latest attempt. A single booking has exactly one row; a weekly
     * lesson (R101) has one row per charge attempt, failed ones first, and once an attempt is
     * captured no later one is made — so the highest `attempt_no` is the captured row when there is one.
     *
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany('attempt_no');
    }
}
