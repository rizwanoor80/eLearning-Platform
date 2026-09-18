<?php

namespace App\Models;

use App\Enums\AvailabilityExceptionType;
use Database\Factories\AvailabilityExceptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tutor_profile_id
 * @property Carbon $date
 * @property string $start_time
 * @property string $end_time
 * @property AvailabilityExceptionType $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AvailabilityException extends Model
{
    /** @use HasFactory<AvailabilityExceptionFactory> */
    use HasFactory;

    protected $fillable = ['tutor_profile_id', 'date', 'start_time', 'end_time', 'type'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => AvailabilityExceptionType::class,
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
