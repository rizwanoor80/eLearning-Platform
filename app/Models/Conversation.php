<?php

namespace App\Models;

use App\Enums\TutorProfileStatus;
use App\Enums\UserStatus;
use App\Support\Messaging\MessageMasker;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The one thread between an account holder and a tutor profile (R133). It exists only once a lesson
 * between them was booked, and outlives cancellations. Contact details are masked in its messages
 * until `first_lesson_completed_at` is set (invariant #8).
 *
 * @property int $id
 * @property int $account_user_id
 * @property int $tutor_profile_id
 * @property Carbon|null $first_lesson_completed_at
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $unread_count set by withCount() on the message list
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected $fillable = ['account_user_id', 'tutor_profile_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_lesson_completed_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_user_id')->withTrashed();
    }

    /**
     * @return BelongsTo<TutorProfile, $this>
     */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The conversations a user is a party to: as the account holder, or as the tutor profile's user.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $parties) => $parties
            ->where('account_user_id', $user->id)
            ->orWhereIn('tutor_profile_id', TutorProfile::query()->where('user_id', $user->id)->select('id')));
    }

    public function contactIsVisible(): bool
    {
        return $this->first_lesson_completed_at !== null;
    }

    public function hasParty(User $user): bool
    {
        return $user->id === $this->account_user_id || $user->id === $this->tutorProfile->user_id;
    }

    /**
     * The other side of this conversation, given one of its parties. Used by the new-message
     * notification/email (8e) to resolve the recipient — never the sender.
     */
    public function otherPartyOf(User $user): User
    {
        return $user->id === $this->account_user_id ? $this->tutorProfile->user : $this->account;
    }

    /**
     * "Closed" when either side is suspended: nobody can send and both see the same neutral notice
     * (R133), so the other party learns nothing about why.
     */
    public function isClosed(): bool
    {
        $tutor = $this->tutorProfile;

        return $tutor->status === TutorProfileStatus::Suspended
            || $tutor->user->status === UserStatus::Suspended
            || $this->account->status === UserStatus::Suspended;
    }

    /**
     * The other side's name as this viewer may see it: a tutor's first name (as everywhere on the
     * public site), an account holder's name for the tutor. No email, no phone: until the first lesson
     * a name that carries one (a user can type anything into it) is masked like a message. The single
     * source of truth for this — reused by MessageController and by the 8e notification/email code —
     * so a contact-detail leak can only be fixed in one place.
     */
    public function counterpartNameFor(User $viewer): string
    {
        $name = $viewer->id === $this->account_user_id
            ? $this->tutorProfile->displayName()
            : $this->account->name;

        if (! $this->contactIsVisible()) {
            $name = app(MessageMasker::class)->mask($name)->text;
        }

        return Str::limit($name, 60);
    }
}
