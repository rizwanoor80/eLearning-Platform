<?php

namespace App\Models;

use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A learner's lesson rated by its account holder (R136), never the learner — a minor never has a
 * login (invariant #7), so `account_user_id` is always `lesson.learner.account_user_id`. Published
 * immediately on create; `published_at` doubles as the publish state (null = unpublished by an
 * admin). `comment` is always masked before it is stored, because reviews are public.
 *
 * @property int $id
 * @property int $lesson_id
 * @property int $tutor_profile_id
 * @property int $account_user_id
 * @property int $rating
 * @property string|null $comment
 * @property Carbon|null $published_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    protected $fillable = ['lesson_id', 'tutor_profile_id', 'account_user_id', 'rating', 'comment', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<TutorProfile, $this>
     */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_user_id')->withTrashed();
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
