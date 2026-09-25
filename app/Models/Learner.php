<?php

namespace App\Models;

use Database\Factories\LearnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A learner never has a login (invariant #7): everything about them goes to
 * the owning account. `account_user_id` and `is_minor` are set only by the
 * learner actions, never mass-assigned.
 *
 * @property int $id
 * @property int $account_user_id
 * @property string $display_name
 * @property bool $is_minor
 * @property int|null $year_group_id
 * @property string|null $year_group_legacy
 * @property int|null $curriculum_id
 * @property string|null $school
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Learner extends Model
{
    /** @use HasFactory<LearnerFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['display_name', 'year_group_id', 'curriculum_id', 'school', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_minor' => 'boolean',
        ];
    }

    /**
     * The adult student's own learner row (created at registration).
     */
    public function isSelf(): bool
    {
        return ! $this->is_minor;
    }

    /**
     * Includes an anonymised (soft-deleted) account (R54): a learner's other
     * data outlives the account, so this must still resolve.
     *
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_user_id')->withTrashed();
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /**
     * @return HasMany<RecurringSlot, $this>
     */
    public function recurringSlots(): HasMany
    {
        return $this->hasMany(RecurringSlot::class);
    }

    /**
     * @return BelongsTo<YearGroup, $this>
     */
    public function yearGroup(): BelongsTo
    {
        return $this->belongsTo(YearGroup::class);
    }

    /**
     * The year group as words: the list's label, or the original text for a
     * legacy row that could not be mapped (the owner picks one on next edit).
     */
    public function yearGroupLabel(): ?string
    {
        return $this->year_group_id === null ? $this->year_group_legacy : $this->yearGroup->label;
    }

    /**
     * @return BelongsTo<Curriculum, $this>
     */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }
}
