<?php

namespace App\Models;

use App\Enums\BudgetTier;
use App\Enums\MatchRequestStatus;
use Database\Factories\MatchRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A parent's request for tutor suggestions. `status`, `suggested_tutor_ids`,
 * `handled_by` and `suggested_at` are never mass-assignable — only
 * SuggestTutors / CloseMatchRequest change them. `year_group` and
 * `curriculum_id` are the request's own snapshot of the learner at the time.
 *
 * @property int $id
 * @property int $account_user_id
 * @property int $learner_id
 * @property int $curriculum_id
 * @property int $subject_id
 * @property string $year_group
 * @property string $goals
 * @property string|null $preferred_times
 * @property BudgetTier $budget_tier
 * @property MatchRequestStatus $status
 * @property list<int>|null $suggested_tutor_ids
 * @property int|null $handled_by
 * @property Carbon|null $suggested_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MatchRequest extends Model
{
    /** @use HasFactory<MatchRequestFactory> */
    use HasFactory;

    protected $fillable = ['curriculum_id', 'subject_id', 'year_group', 'goals', 'preferred_times', 'budget_tier'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'budget_tier' => BudgetTier::class,
            'status' => MatchRequestStatus::class,
            'suggested_tutor_ids' => 'array',
            'suggested_at' => 'datetime',
        ];
    }

    /**
     * Includes an anonymised (soft-deleted) account (R54), matching
     * `learner()` below.
     *
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_user_id')->withTrashed();
    }

    /**
     * Includes a soft-deleted learner so the queue and the parent page can
     * still show who the request was for.
     *
     * @return BelongsTo<Learner, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class)->withTrashed();
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

    /**
     * No `->withTrashed()`: `handled_by` only ever points to an admin, and
     * `AnonymizeUser` refuses an admin target while `DisableAdminUser` only
     * suspends (never soft-deletes) one, so this row never needs to resolve
     * a trashed user.
     *
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
