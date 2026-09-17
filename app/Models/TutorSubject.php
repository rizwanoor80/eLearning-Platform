<?php

namespace App\Models;

use App\Enums\LevelTier;
use Database\Factories\TutorSubjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tutor_profile_id
 * @property int $curriculum_id
 * @property int $subject_id
 * @property string $level_min
 * @property string $level_max
 * @property LevelTier $level_tier
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TutorSubject extends Model
{
    /** @use HasFactory<TutorSubjectFactory> */
    use HasFactory;

    protected $fillable = ['tutor_profile_id', 'curriculum_id', 'subject_id', 'level_min', 'level_max', 'level_tier'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level_tier' => LevelTier::class,
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
}
