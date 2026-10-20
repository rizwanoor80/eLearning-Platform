<?php

namespace App\Models;

use Database\Factories\PayoutBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One admin run of tutor payouts (23h, R251; table `payout_batches`). The period is a label (the schedule the run
 * belongs to): which tutors are in it is decided by their available balance on the day it is generated.
 *
 * @property int $id
 * @property Carbon|null $period_start
 * @property Carbon $period_end
 * @property int $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $lines_count
 * @property-read int|string|null $lines_sum_amount
 * @property-read int|null $pending_lines_count
 * @property-read int|null $paid_lines_count
 * @property-read int|null $failed_lines_count
 */
class PayoutBatch extends Model
{
    /** @use HasFactory<PayoutBatchFactory> */
    use HasFactory;

    protected $fillable = ['period_start', 'period_end', 'created_by_user_id'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    /**
     * @return HasMany<PayoutBatchLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayoutBatchLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
