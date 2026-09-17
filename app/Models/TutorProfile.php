<?php

namespace App\Models;

use App\Enums\TutorProfileStatus;
use App\Support\Money;
use Database\Factories\TutorProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $headline
 * @property string|null $bio
 * @property string|null $intro_video_url
 * @property Money|null $hourly_rate
 * @property TutorProfileStatus $status
 * @property string|null $permit_number
 * @property Carbon|null $permit_expires_at
 * @property Carbon|null $agreement_accepted_at
 * @property int|null $agreement_version
 * @property string|null $bank_name
 * @property string|null $bank_account_name
 * @property string|null $bank_iban
 * @property string|null $bank_swift
 * @property Carbon|null $bank_verified_at
 * @property string $rating_avg
 * @property int $rating_count
 * @property int $lessons_completed
 * @property int $late_report_count_90d
 * @property int $strike_count_90d
 * @property string|null $review_note
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TutorProfile extends Model
{
    /** @use HasFactory<TutorProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'status', 'headline', 'bio', 'intro_video_url', 'hourly_rate',
        'permit_number', 'permit_expires_at',
        'agreement_accepted_at', 'agreement_version',
        'bank_name', 'bank_account_name', 'bank_iban', 'bank_swift',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hourly_rate' => Money::class,
            'status' => TutorProfileStatus::class,
            'permit_expires_at' => 'date',
            'agreement_accepted_at' => 'datetime',
            'bank_name' => 'encrypted',
            'bank_account_name' => 'encrypted',
            'bank_iban' => 'encrypted',
            'bank_swift' => 'encrypted',
            'bank_verified_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Bookable = approved AND the permit has not expired yet (strictly after
     * today — a permit expiring today is not bookable). Never re-implement
     * this condition elsewhere.
     *
     * @param  Builder<TutorProfile>  $query
     * @return Builder<TutorProfile>
     */
    public function scopeBookable(Builder $query): Builder
    {
        return $query
            ->where('status', TutorProfileStatus::Approved)
            ->whereDate('permit_expires_at', '>', Date::today());
    }

    /**
     * Last four of the IBAN only — the full value is never shown outside the
     * admin payout view (CP5).
     */
    public function bankIbanMasked(): ?string
    {
        if ($this->bank_iban === null) {
            return null;
        }

        return str_repeat('•', max(strlen($this->bank_iban) - 4, 0)).substr($this->bank_iban, -4);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<TutorDocument, $this>
     */
    public function tutorDocuments(): HasMany
    {
        return $this->hasMany(TutorDocument::class);
    }

    /**
     * @return HasMany<TutorSubject, $this>
     */
    public function tutorSubjects(): HasMany
    {
        return $this->hasMany(TutorSubject::class);
    }

    /**
     * @return HasMany<AvailabilityRule, $this>
     */
    public function availabilityRules(): HasMany
    {
        return $this->hasMany(AvailabilityRule::class);
    }

    /**
     * @return HasMany<AvailabilityException, $this>
     */
    public function availabilityExceptions(): HasMany
    {
        return $this->hasMany(AvailabilityException::class);
    }
}
