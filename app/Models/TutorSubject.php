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
 * @property int|null $level_min_id
 * @property int|null $level_max_id
 * @property string|null $level_min_legacy
 * @property string|null $level_max_legacy
 * @property LevelTier $level_tier
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TutorSubject extends Model
{
    /** @use HasFactory<TutorSubjectFactory> */
    use HasFactory;

    protected $fillable = ['tutor_profile_id', 'curriculum_id', 'subject_id', 'level_min_id', 'level_max_id', 'level_tier'];

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
     * The lowest year group taught. Null only for a legacy row whose text could
     * not be mapped (its original words are in `level_min_legacy`).
     *
     * @return BelongsTo<YearGroup, $this>
     */
    public function levelMin(): BelongsTo
    {
        return $this->belongsTo(YearGroup::class, 'level_min_id');
    }

    /**
     * The highest year group taught (null only for an unmapped legacy row).
     *
     * @return BelongsTo<YearGroup, $this>
     */
    public function levelMax(): BelongsTo
    {
        return $this->belongsTo(YearGroup::class, 'level_max_id');
    }

    /**
     * How this row's level reads on a public page: the year-group labels, or
     * the original text for an unmapped legacy row.
     */
    public function levelMinLabel(): string
    {
        return $this->level_min_id === null ? (string) $this->level_min_legacy : $this->levelMin->label;
    }

    public function levelMaxLabel(): string
    {
        return $this->level_max_id === null ? (string) $this->level_max_legacy : $this->levelMax->label;
    }

    /**
     * True while either end still has no year group (an unmapped legacy row):
     * the owner has to pick one before the row counts as complete.
     */
    public function isUnmapped(): bool
    {
        return $this->level_min_id === null || $this->level_max_id === null;
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
