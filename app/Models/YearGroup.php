<?php

namespace App\Models;

use App\Enums\LevelTier;
use Database\Factories\YearGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A controlled year group of one curriculum (R33). `sort` orders the groups
 * within the curriculum and drives every range ("Year 7 to Year 9"); the
 * `level_tier` decides which price band applies.
 *
 * @property int $id
 * @property int $curriculum_id
 * @property string $code
 * @property string $label
 * @property int $sort
 * @property LevelTier $level_tier
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class YearGroup extends Model
{
    /** @use HasFactory<YearGroupFactory> */
    use HasFactory;

    protected $fillable = ['curriculum_id', 'code', 'label', 'sort', 'level_tier'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'level_tier' => LevelTier::class,
        ];
    }

    /**
     * @return BelongsTo<Curriculum, $this>
     */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /**
     * @return HasMany<Learner, $this>
     */
    public function learners(): HasMany
    {
        return $this->hasMany(Learner::class);
    }

    /**
     * @return HasMany<TutorSubject, $this>
     */
    public function subjectsAsMin(): HasMany
    {
        return $this->hasMany(TutorSubject::class, 'level_min_id');
    }

    /**
     * @return HasMany<TutorSubject, $this>
     */
    public function subjectsAsMax(): HasMany
    {
        return $this->hasMany(TutorSubject::class, 'level_max_id');
    }

    /**
     * Whether anything still points at this year group (so it cannot be deleted).
     * Soft-deleted learners count: the foreign key still holds their row.
     */
    public function isReferenced(): bool
    {
        return $this->learners()->withTrashed()->exists() || $this->subjectsAsMin()->exists() || $this->subjectsAsMax()->exists();
    }
}
