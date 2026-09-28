<?php

namespace App\Models;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use Database\Factories\DisputeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CP8 (R150, DATA_MODEL §disputes). One per lesson (DB unique index on
 * `lesson_id`). `parent_refund_pct`/`tutor_pay_pct` are the admin's two
 * dials, set at resolution; `refund_amount`/`tutor_paid_amount`/
 * `platform_delta` are the fils `LedgerService::settle()` actually wrote —
 * a record, never an input to it.
 *
 * @property int $id
 * @property int $lesson_id
 * @property int $opened_by_user_id
 * @property DisputeReason $reason
 * @property string $description
 * @property DisputeStatus $status
 * @property int|null $parent_refund_pct
 * @property int|null $tutor_pay_pct
 * @property int|null $refund_amount
 * @property int|null $tutor_paid_amount
 * @property int|null $platform_delta
 * @property string|null $admin_note
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Dispute extends Model
{
    /** @use HasFactory<DisputeFactory> */
    use HasFactory;

    protected $fillable = [
        'lesson_id',
        'opened_by_user_id',
        'reason',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => DisputeReason::class,
            'status' => DisputeStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by')->withTrashed();
    }
}
