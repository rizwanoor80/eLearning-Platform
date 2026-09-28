<?php

namespace App\Models;

use App\Enums\AbuseReportReason;
use App\Enums\AbuseReportStatus;
use App\Enums\AbuseReportSubjectType;
use Database\Factories\AbuseReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CP7 (R137): a safeguarding report against a tutor profile, a user, a lesson or a
 * conversation. `subject_type`/`subject_id` are never an Eloquent `morphTo()` — this
 * codebase enforces no morph map, so `reportedUser()` below resolves the reported party
 * at read time with a plain `match`, never a stored FK. The reported party is never
 * notified that a report was filed (only admins are, via `AbuseReportFiled`).
 *
 * @property int $id
 * @property int $reporter_user_id
 * @property AbuseReportSubjectType $subject_type
 * @property int $subject_id
 * @property AbuseReportReason $reason
 * @property string $description
 * @property AbuseReportStatus $status
 * @property string|null $action_taken
 * @property int|null $handled_by
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AbuseReport extends Model
{
    /** @use HasFactory<AbuseReportFactory> */
    use HasFactory;

    protected $fillable = [
        'reporter_user_id',
        'subject_type',
        'subject_id',
        'reason',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_type' => AbuseReportSubjectType::class,
            'reason' => AbuseReportReason::class,
            'status' => AbuseReportStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by')->withTrashed();
    }

    /**
     * The reported party, resolved from `subject_type`/`subject_id` at read time — never a
     * stored FK. For a lesson or a conversation the subject itself has two parties (a
     * tutor and an account holder, invariant #7's parent, never the learner); the reported
     * party is whichever one is not the reporter. Returns null if the subject row (or its
     * user) no longer resolves.
     */
    public function reportedUser(): ?User
    {
        return match ($this->subject_type) {
            // Neither TutorProfile nor Lesson uses SoftDeletes (only User does) — a plain
            // find() already sees every row that still exists; withTrashed() here would be a
            // fatal call to an undefined method, not a no-op.
            AbuseReportSubjectType::TutorProfile => TutorProfile::query()
                ->find($this->subject_id)
                ?->user,

            AbuseReportSubjectType::User => User::withTrashed()->find($this->subject_id),

            AbuseReportSubjectType::Lesson => $this->otherPartyForLesson(
                Lesson::query()->find($this->subject_id)
            ),

            AbuseReportSubjectType::Conversation => $this->otherPartyForConversation(
                Conversation::query()->find($this->subject_id)
            ),
        };
    }

    private function otherPartyForLesson(?Lesson $lesson): ?User
    {
        if ($lesson === null) {
            return null;
        }

        $tutorUserId = $lesson->tutorProfile?->user_id;
        $accountUserId = $lesson->learner?->account_user_id;

        if ($this->reporter_user_id === $tutorUserId) {
            return $accountUserId !== null ? User::withTrashed()->find($accountUserId) : null;
        }

        return $tutorUserId !== null ? User::withTrashed()->find($tutorUserId) : null;
    }

    private function otherPartyForConversation(?Conversation $conversation): ?User
    {
        if ($conversation === null) {
            return null;
        }

        $tutorUserId = $conversation->tutorProfile?->user_id;

        if ($this->reporter_user_id === $tutorUserId) {
            return User::withTrashed()->find($conversation->account_user_id);
        }

        return $tutorUserId !== null ? User::withTrashed()->find($tutorUserId) : null;
    }
}
