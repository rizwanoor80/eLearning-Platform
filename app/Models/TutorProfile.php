<?php

namespace App\Models;

use App\Actions\Lessons\ReviewLateReports;
use App\Enums\AbuseReportStatus;
use App\Enums\AbuseReportSubjectType;
use App\Enums\DisputeStatus;
use App\Enums\TutorDocumentStatus;
use App\Enums\TutorProfileStatus;
use App\Support\Facades\Settings;
use App\Support\Money;
use Database\Factories\TutorProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $country
 * @property string|null $headline
 * @property string|null $bio
 * @property string|null $intro_video_url
 * @property string|null $linkedin_url
 * @property Money|null $hourly_rate
 * @property int|null $min_lead_hours The tutor's own booking lead time (R179); null = platform default. Read it only through `BookingLeadTime::for()`.
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
 * @property array<int, string>|null $review_sections
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TutorProfile extends Model
{
    /** @use HasFactory<TutorProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'country', 'headline', 'bio', 'intro_video_url', 'linkedin_url', 'hourly_rate', 'min_lead_hours',
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
            'min_lead_hours' => 'integer',
            'review_sections' => 'array',
            'status' => TutorProfileStatus::class,
            'permit_expires_at' => 'date',
            'agreement_accepted_at' => 'datetime',
            'bank_name' => 'encrypted',
            'bank_account_name' => 'encrypted',
            'bank_iban' => 'encrypted',
            'bank_swift' => 'encrypted',
            'bank_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * Bookable = approved AND at least one weekly availability window (R185) AND the permit
     * does not block booking (R170: no permit at all is fine
     * — a tutor need not be in the UAE; a permit that exists must not have expired, strictly
     * after today) AND the owning user has not been deleted. The third condition enforces
     * invariant #5 against R54: `AnonymizeUser` suspends an approved tutor's profile on deletion,
     * but `ReinstateTutor` can later take `Suspended -> Approved` again (a
     * valid edge on its own terms), so `status = Approved` alone is not
     * enough once a user can be soft-deleted — this scope must also check
     * `users.deleted_at` directly. Checked against the column rather than
     * through the `user()` relation below, since that relation deliberately
     * includes trashed rows for display. Never re-implement this condition
     * elsewhere.
     *
     * @param  Builder<TutorProfile>  $query
     * @return Builder<TutorProfile>
     */
    public function scopeBookable(Builder $query): Builder
    {
        return $query
            ->where('status', TutorProfileStatus::Approved)
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('permit_expires_at')
                ->orWhereDate('permit_expires_at', '>', Date::today()))
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.id', 'tutor_profiles.user_id')
                    ->whereNull('users.deleted_at');
            })
            // R185: approval no longer needs availability, being listed and booked does — an
            // approved tutor with no weekly window is not offered to anyone.
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('availability_rules')
                    ->whereColumn('availability_rules.tutor_profile_id', 'tutor_profiles.id');
            });
    }

    /**
     * What a public page or a parent email calls this tutor: the first word of
     * the account name (R32). Splits on any Unicode whitespace and ignores empty
     * pieces, so a leading no-break space or tab cannot produce an empty name.
     * The one source — search, the profile and match suggestions all use it;
     * admin screens deliberately keep the full name.
     */
    public function displayName(): string
    {
        // Leading invisible format characters (zero-width space, BOM, direction
        // marks) are dropped with the whitespace, so they cannot become the
        // "first word". Only the LEADING run: an internal zero-width joiner or
        // non-joiner is part of many Persian and Indic names and stays.
        $name = preg_replace('/^[\p{Cf}\s]+/u', '', (string) $this->user->name) ?? '';
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);

        return $parts[0] ?? 'Tutor';
    }

    /**
     * The trial-lesson price: the hourly rate minus `trial_discount_pct` (PRD
     * §2.4). The one place this is computed — the profile shows it and CP3's
     * `BookLesson` freezes it from here, so what is displayed is what is charged.
     *
     * Rounding rule: price = rate − round_half_up(rate × pct / 100), so the
     * half-fil goes to the parent's discount side. It is NOT
     * `percentage(100 − pct)`, which rounds the other way (10001 @ 50% is
     * 5000 here, 5001 there) — callers must use this method, never recompute.
     */
    public function trialPrice(): ?Money
    {
        if ($this->hourly_rate === null) {
            return null;
        }

        return $this->hourly_rate->subtract($this->hourly_rate->percentage((int) Settings::get('trial_discount_pct')));
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
     * Includes an anonymised (soft-deleted) user (R54) so `displayName()`,
     * admin screens and past-lesson views can still resolve the row instead
     * of throwing — `bookable()` independently excludes a deleted user, so
     * this does not affect invariant #5.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
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

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * @return HasMany<TutorStrike, $this>
     */
    public function strikes(): HasMany
    {
        return $this->hasMany(TutorStrike::class);
    }

    /**
     * R151 admin tutor detail view: this tutor's lessons with a late progress-report flag
     * (`report_late_at` set) inside the same rolling window `ReviewLateReports` counts against
     * the 3-strike threshold — so what the admin sees here always matches what would actually
     * trigger a review, never a stale stored counter (`late_report_count_90d` on this model is
     * declared but unmaintained; this method is the source of truth instead).
     *
     * @return Collection<int, Lesson>
     */
    public function lateReportFlags(): Collection
    {
        return $this->lessons()
            ->where('report_late_at', '>=', now()->subDays(ReviewLateReports::WINDOW_DAYS))
            ->orderByDesc('report_late_at')
            ->get(['id', 'report_late_at']);
    }

    /**
     * R151 admin tutor detail view: open safeguarding reports where this tutor is the reported
     * party. `AbuseReport` carries no real morph relation (see that model's docblock) and
     * `reportedUser()` resolves the reported party in PHP per row — so this first narrows to
     * reports whose subject could plausibly be this tutor (their own profile row, a direct
     * `User` row against their account, or one of their lessons or conversations — every case
     * `AbuseReportSubjectType` declares), then confirms each one in PHP before returning it. A
     * report against the account on the *other* side of one of this tutor's lessons or
     * conversations is correctly excluded by that confirmation step.
     *
     * @return Collection<int, AbuseReport>
     */
    public function openAbuseReports(): Collection
    {
        $lessonIds = $this->lessons()->pluck('id');
        $conversationIds = Conversation::query()->where('tutor_profile_id', $this->id)->pluck('id');

        return AbuseReport::query()
            ->where('status', AbuseReportStatus::Open)
            ->where(function (Builder $query) use ($lessonIds, $conversationIds): void {
                $query->where(function (Builder $q): void {
                    $q->where('subject_type', AbuseReportSubjectType::TutorProfile)->where('subject_id', $this->id);
                })
                    ->orWhere(function (Builder $q): void {
                        $q->where('subject_type', AbuseReportSubjectType::User)->where('subject_id', $this->user_id);
                    })
                    ->orWhere(function (Builder $q) use ($lessonIds): void {
                        $q->where('subject_type', AbuseReportSubjectType::Lesson)->whereIn('subject_id', $lessonIds);
                    })
                    ->orWhere(function (Builder $q) use ($conversationIds): void {
                        $q->where('subject_type', AbuseReportSubjectType::Conversation)->whereIn('subject_id', $conversationIds);
                    });
            })
            ->get()
            ->filter(fn (AbuseReport $report): bool => $report->reportedUser()?->id === $this->user_id)
            ->values();
    }

    /**
     * R151 admin tutor detail view: open disputes on this tutor's lessons.
     *
     * @return Collection<int, Dispute>
     */
    public function openDisputes(): Collection
    {
        return Dispute::query()
            ->whereHas('lesson', fn (Builder $query): Builder => $query->where('tutor_profile_id', $this->id))
            ->where('status', DisputeStatus::Open)
            ->with('lesson:id,starts_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * R151 admin tutor detail view: this tutor's suspension history, read directly from the
     * append-only audit log ahead of 9d's general audit resource (design consult, CYCLE-LOG
     * `08:02`) — also the reader for `tutor.suspension_sweep` rows STATUS §6 item P flagged as
     * otherwise unread anywhere.
     *
     * @return Collection<int, AuditLog>
     */
    public function suspensionHistory(): Collection
    {
        return AuditLog::query()
            ->where('subject_type', self::class)
            ->where('subject_id', $this->id)
            ->whereIn('action', ['tutor.suspended', 'tutor.reinstated', 'tutor.suspension_sweep'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Whether the permit is still valid today — the PHP twin of the permit
     * half of `scopeBookable()` (expiry strictly after today, by date), so
     * approval, reinstatement and search can never disagree about one tutor.
     */
    public function permitIsValid(): bool
    {
        return $this->permit_expires_at !== null
            && $this->permit_expires_at->toDateString() > Date::today()->toDateString();
    }

    /**
     * R170: whether a permit, if the tutor has one at all, allows booking — the PHP twin of
     * `scopeBookable()`'s permit clause. Unlike `permitIsValid()` above (present AND not expired,
     * used by the permit-expiry reminder and the "expiring permits" widget, which only ever apply
     * to a tutor who has a permit), a tutor with no permit at all is not a problem here: they
     * simply never needed one (R170 — tutors may be anywhere, the UAE permit is optional for
     * everyone). Used by `scopeBookable()` and `TutorApprovalReadiness`; never re-implement
     * elsewhere.
     */
    public function permitAllowsBooking(): bool
    {
        return $this->permit_expires_at === null
            || $this->permit_expires_at->toDateString() > Date::today()->toDateString();
    }

    /**
     * R171: onboarding's CV-or-LinkedIn requirement — a LinkedIn URL on file, or a CV document
     * that has not been rejected (pending or accepted both count; a tutor who has not heard back
     * yet should not be asked to resubmit). Drives `TutorOnboardingController::currentStep()`'s
     * mandatory-step gating and the tutor dashboard's "what's missing" list; never re-implement
     * elsewhere.
     */
    public function hasCvOrLinkedin(): bool
    {
        if ($this->linkedin_url !== null) {
            return true;
        }

        return $this->tutorDocuments()
            ->whereHas('documentType', fn (Builder $query): Builder => $query->where('code', DocumentType::CV_CODE))
            ->where('status', '!=', TutorDocumentStatus::Rejected)
            ->exists();
    }

    /**
     * CP1 box 6/acceptance: approval is blocked while any active, required
     * document type lacks an `accepted` current document. Never
     * re-implement this condition elsewhere — the approval action and the
     * Filament approval queue's UI both read it from here.
     */
    public function hasAllRequiredDocumentsAccepted(): bool
    {
        $requiredTypeIds = DocumentType::query()->active()->where('required', true)->pluck('id');

        if ($requiredTypeIds->isEmpty()) {
            return true;
        }

        $acceptedTypeIds = $this->tutorDocuments()
            ->where('status', TutorDocumentStatus::Accepted)
            ->pluck('document_type_id');

        return $requiredTypeIds->diff($acceptedTypeIds)->isEmpty();
    }
}
