<?php

namespace App\Models;

use App\Enums\LessonStatus;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CP2 stub: only what SlotCalculator reads. CP3 extends the table and owns
 * every status change through LessonStateMachine — nothing here mutates it.
 *
 * @property int $id
 * @property int $tutor_profile_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property LessonStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    // `status` is deliberately not mass-assignable: it changes only through
    // LessonStateMachine (CP3), which will `forceFill` it at creation.
    protected $fillable = ['tutor_profile_id', 'starts_at', 'ends_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => LessonStatus::class,
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
