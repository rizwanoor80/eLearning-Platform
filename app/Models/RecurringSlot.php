<?php

namespace App\Models;

use App\Enums\RecurringSlotStatus;
use Database\Factories\RecurringSlotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CP2 stub: only what SlotCalculator reads. CP4 extends the table.
 *
 * @property int $id
 * @property int $tutor_profile_id
 * @property int $weekday
 * @property string $start_time
 * @property string $timezone
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property RecurringSlotStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RecurringSlot extends Model
{
    /** @use HasFactory<RecurringSlotFactory> */
    use HasFactory;

    protected $fillable = ['tutor_profile_id', 'weekday', 'start_time', 'timezone', 'starts_on', 'ends_on', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => RecurringSlotStatus::class,
        ];
    }

    /**
     * @return BelongsTo<TutorProfile, $this>
     */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }
}
