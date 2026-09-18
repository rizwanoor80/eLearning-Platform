<?php

namespace App\Models;

use Database\Factories\LearnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property string|null $year_group
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

    protected $fillable = ['display_name', 'year_group', 'curriculum_id', 'school', 'notes'];

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
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_user_id');
    }

    /**
     * @return BelongsTo<Curriculum, $this>
     */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }
}
