<?php

namespace App\Models;

use App\Enums\StrikeType;
use Database\Factories\TutorStrikeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A strike against a tutor (CP3 3d creates them; three within 90 days suspend
 * the tutor). History only — never edited.
 *
 * @property int $id
 * @property int $tutor_profile_id
 * @property int|null $lesson_id
 * @property StrikeType $type
 * @property string|null $note
 * @property Carbon|null $created_at
 */
class TutorStrike extends Model
{
    /** @use HasFactory<TutorStrikeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['tutor_profile_id', 'lesson_id', 'type', 'note'];

    /**
     * @return array<string, class-string|string>
     */
    protected function casts(): array
    {
        return [
            'type' => StrikeType::class,
            'created_at' => 'datetime',
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
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
