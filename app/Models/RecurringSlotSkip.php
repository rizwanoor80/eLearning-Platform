<?php

namespace App\Models;

use App\Enums\RecurringSlotSkipReason;
use Database\Factories\RecurringSlotSkipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An occurrence `recurring:generate` did not create (R98). `notified_at` makes the parent
 * email idempotent; unique on `(recurring_slot_id, starts_at)`.
 *
 * @property int $id
 * @property int $recurring_slot_id
 * @property Carbon $starts_at
 * @property RecurringSlotSkipReason $reason
 * @property Carbon|null $notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RecurringSlotSkip extends Model
{
    /** @use HasFactory<RecurringSlotSkipFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'reason' => RecurringSlotSkipReason::class,
            'notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RecurringSlot, $this>
     */
    public function recurringSlot(): BelongsTo
    {
        return $this->belongsTo(RecurringSlot::class);
    }
}
