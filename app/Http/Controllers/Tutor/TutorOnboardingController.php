<?php

namespace App\Http\Controllers\Tutor;

use App\Actions\Tutor\CompleteTutorOnboarding;
use App\Enums\LevelTier;
use App\Enums\TutorDocumentStatus;
use App\Enums\TutorProfileStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tutor\Onboarding\AgreementStepRequest;
use App\Http\Requests\Tutor\Onboarding\AvailabilityStepRequest;
use App\Http\Requests\Tutor\Onboarding\BankStepRequest;
use App\Http\Requests\Tutor\Onboarding\DocumentStepRequest;
use App\Http\Requests\Tutor\Onboarding\PermitStepRequest;
use App\Http\Requests\Tutor\Onboarding\PersonalStepRequest;
use App\Http\Requests\Tutor\Onboarding\ProfileStepRequest;
use App\Http\Requests\Tutor\Onboarding\RateStepRequest;
use App\Http\Requests\Tutor\Onboarding\SubjectsStepRequest;
use App\Models\Curriculum;
use App\Models\DocumentType;
use App\Models\Page;
use App\Models\PriceBand;
use App\Models\Subject;
use App\Models\TutorDocument;
use App\Models\TutorProfile;
use App\Models\User;
use App\Support\Facades\Settings;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TutorOnboardingController extends Controller
{
    /**
     * The order steps unlock in. Used only to refuse a request for a step
     * later than the wizard's own derived position (never to derive the
     * step itself, which always comes from saved state).
     *
     * @var array<int, string>
     */
    private const STEP_ORDER = [
        'personal', 'permit', 'document', 'bank', 'subjects', 'rate', 'profile', 'availability', 'agreement',
    ];

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $step = $this->currentStep($user, $profile);

        $agreementPage = Page::query()->where('slug', 'tutor_agreement')->first();

        return Inertia::render('tutor/Onboarding', [
            'step' => $step['name'],
            'currentDocumentType' => $step['documentType'] ?? null,
            'status' => $profile->status->value,
            // The admin's note is shown for changes_requested (what to fix) and
            // suspended (why) — SuspendTutor promises the tutor sees it (R31).
            'reviewNote' => in_array($profile->status, [TutorProfileStatus::ChangesRequested, TutorProfileStatus::Suspended], true)
                ? $profile->review_note
                : null,
            'personal' => [
                'phone' => $user->phone,
                'timezone' => $user->timezone,
            ],
            'profile' => [
                'permit_number' => $profile->permit_number,
                'permit_expires_at' => $profile->permit_expires_at?->toDateString(),
                'bank_name' => $profile->bank_name,
                'bank_account_name' => $profile->bank_account_name,
                'bank_iban_masked' => $profile->bankIbanMasked(),
                'bank_swift' => $profile->bank_swift,
                'headline' => $profile->headline,
                'bio' => $profile->bio,
                'intro_video_url' => $profile->intro_video_url,
                'hourly_rate' => $profile->hourly_rate?->toFils(),
            ],
            'documentTypes' => DocumentType::query()->active()->orderBy('sort')
                ->get(['id', 'code', 'name', 'description']),
            'documents' => $profile->tutorDocuments()
                ->get(['id', 'document_type_id', 'original_name', 'status']),
            'curricula' => Curriculum::query()->orderBy('sort')->get(['id', 'code', 'name']),
            'subjects' => Subject::query()->orderBy('sort')->get(['id', 'name']),
            'tutorSubjects' => $profile->tutorSubjects()
                ->get(['id', 'curriculum_id', 'subject_id', 'level_min', 'level_max', 'level_tier']),
            'rateBand' => $this->rateBandFor($profile),
            'trialDiscountPct' => Settings::get('trial_discount_pct'),
            'availabilityRules' => $profile->availabilityRules()
                ->get(['id', 'weekday', 'start_time', 'end_time']),
            'availabilityExceptions' => $profile->availabilityExceptions()
                ->get(['id', 'date', 'start_time', 'end_time', 'type']),
            'agreement' => [
                'current_version' => $agreementPage?->version,
                'title' => $agreementPage?->title,
                'body' => $agreementPage?->body,
                'accepted_at' => $profile->agreement_accepted_at?->toIso8601String(),
                'accepted_version' => $profile->agreement_version,
            ],
        ]);
    }

    public function storePersonal(PersonalStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $this->guardStepNotAhead($user, $profile, 'personal');

        $user->update($request->validated());

        return redirect()->route('tutor.onboarding');
    }

    public function storePermit(PermitStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $this->guardStepNotAhead($user, $profile, 'permit');

        $profile->update($request->validated());

        return redirect()->route('tutor.onboarding');
    }

    public function storeDocument(DocumentStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);

        $step = $this->currentStep($user, $profile);
        $documentType = $step['documentType'] ?? null;
        abort_unless($documentType !== null, 409);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $path = $file->store('tutor-documents/'.$profile->id, 'local');

        DB::transaction(function () use ($profile, $documentType, $file, $path): void {
            $profile->tutorDocuments()->where('document_type_id', $documentType->id)->delete();

            TutorDocument::query()->create([
                'tutor_profile_id' => $profile->id,
                'document_type_id' => $documentType->id,
                'disk_path' => $path,
                'original_name' => $file->getClientOriginalName(),
            ]);
        });

        return redirect()->route('tutor.onboarding');
    }

    public function storeBank(BankStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $this->guardStepNotAhead($user, $profile, 'bank');

        $profile->update($request->validated());

        return redirect()->route('tutor.onboarding');
    }

    public function storeSubjects(SubjectsStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $this->guardStepNotAhead($user, $profile, 'subjects');

        /** @var array<int, array{curriculum_id: int, subject_id: int, level_min: string, level_max: string, level_tier: string}> $subjectsInput */
        $subjectsInput = $request->validated('subjects');
        $rows = collect($subjectsInput);

        $curricula = Curriculum::query()->whereIn('id', $rows->pluck('curriculum_id')->unique())->get()->keyBy('id');

        $seen = [];
        foreach ($rows as $row) {
            /** @var Curriculum|null $curriculum */
            $curriculum = $curricula->get($row['curriculum_id']);
            abort_if($curriculum === null, 422);

            $tier = LevelTier::from($row['level_tier']);
            if (! in_array($tier, $curriculum->code->tiers(), true)) {
                throw ValidationException::withMessages([
                    'subjects' => "The level {$tier->value} does not exist for {$curriculum->code->value}.",
                ]);
            }

            $key = $row['curriculum_id'].':'.$row['subject_id'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'subjects' => 'Each subject may only be listed once per curriculum.',
                ]);
            }
            $seen[$key] = true;
        }

        DB::transaction(function () use ($profile, $rows): void {
            $profile->tutorSubjects()->delete();

            foreach ($rows as $row) {
                $profile->tutorSubjects()->create([
                    'curriculum_id' => $row['curriculum_id'],
                    'subject_id' => $row['subject_id'],
                    'level_min' => $row['level_min'],
                    'level_max' => $row['level_max'],
                    'level_tier' => $row['level_tier'],
                ]);
            }
        });

        $this->invalidateRateIfOutOfBand($profile);

        return redirect()->route('tutor.onboarding');
    }

    public function storeRate(RateStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $this->guardStepNotAhead($user, $profile, 'rate');

        $band = $this->rateBandFor($profile);
        abort_if($band === null, 409);

        if ($band['conflicting'] !== []) {
            throw ValidationException::withMessages([
                'hourly_rate' => sprintf(
                    'No single rate satisfies every curriculum you teach at this level — %s have non-overlapping bands. Adjust your subjects or contact support.',
                    implode(' and ', $band['conflicting']),
                ),
            ]);
        }

        $rate = Money::fromDecimalString((string) $request->validated('hourly_rate'));

        if ($rate->toFils() < $band['min'] || $rate->toFils() > $band['max']) {
            throw ValidationException::withMessages([
                'hourly_rate' => sprintf(
                    'Your rate must be between %s and %s for the highest level you teach.',
                    Money::fils($band['min'])->format(),
                    Money::fils($band['max'])->format(),
                ),
            ]);
        }

        $profile->update(['hourly_rate' => $rate]);

        return redirect()->route('tutor.onboarding');
    }

    public function storeProfile(ProfileStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $this->guardStepNotAhead($user, $profile, 'profile');

        $profile->update($request->validated());

        return redirect()->route('tutor.onboarding');
    }

    public function storeAvailability(AvailabilityStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $this->guardStepNotAhead($user, $profile, 'availability');

        /** @var array<int, array{weekday: int, start_time: string, end_time: string}> $rulesInput */
        $rulesInput = $request->validated('rules');
        $rules = collect($rulesInput);

        /** @var array<int, array{date: string, start_time: string, end_time: string, type: string}> $exceptionsInput */
        $exceptionsInput = $request->validated('exceptions') ?? [];
        $exceptions = collect($exceptionsInput);

        foreach ($rules->groupBy('weekday') as $weekday => $dayRules) {
            $sorted = $dayRules->sortBy('start_time')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                if ($sorted[$i]['start_time'] < $sorted[$i - 1]['end_time']) {
                    throw ValidationException::withMessages([
                        'rules' => "Availability rules for weekday {$weekday} overlap.",
                    ]);
                }
            }
        }

        DB::transaction(function () use ($profile, $rules, $exceptions, $user): void {
            $profile->availabilityRules()->delete();
            $profile->availabilityExceptions()->delete();

            foreach ($rules as $rule) {
                $profile->availabilityRules()->create([
                    'weekday' => $rule['weekday'],
                    'start_time' => $rule['start_time'],
                    'end_time' => $rule['end_time'],
                    'timezone' => $user->timezone,
                ]);
            }

            foreach ($exceptions as $exception) {
                $profile->availabilityExceptions()->create($exception);
            }
        });

        return redirect()->route('tutor.onboarding');
    }

    public function storeAgreement(AgreementStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $this->guardStepNotAhead($user, $profile, 'agreement');

        $page = Page::query()->where('slug', 'tutor_agreement')->firstOrFail();

        $profile->update([
            'agreement_accepted_at' => now(),
            'agreement_version' => $page->version,
        ]);

        return redirect()->route('tutor.onboarding');
    }

    public function complete(Request $request, CompleteTutorOnboarding $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);

        abort_unless($this->currentStep($user, $profile)['name'] === 'complete', 409);

        // Defence in depth (R27): completion re-checks the stored rate
        // against the *current* band rather than trusting that it was
        // still valid when saved — invalidateRateIfOutOfBand() already
        // clears a stale rate on every subjects change, so this should
        // never actually fire, but a 409 here is cheap insurance against
        // any other path that could leave hourly_rate stale.
        $band = $this->rateBandFor($profile);
        $rateFils = $profile->hourly_rate?->toFils();
        abort_if(
            $band === null || $band['conflicting'] !== [] || $rateFils === null
                || $rateFils < $band['min'] || $rateFils > $band['max'],
            409,
        );

        $action($profile);

        return redirect()->route('tutor.onboarding');
    }

    /**
     * `status` is deliberately not mass-assignable (same convention as
     * `User::role`) since 1c's admin-approval flow writes it too — every
     * write path here sets it explicitly via `forceFill()` instead.
     */
    private function profileFor(User $user): TutorProfile
    {
        $profile = TutorProfile::query()->firstOrNew(['user_id' => $user->id]);

        if (! $profile->exists) {
            $profile->forceFill(['status' => TutorProfileStatus::Draft])->save();
        }

        return $profile;
    }

    /**
     * The current step is derived server-side from what's actually saved —
     * never from client input — so the wizard is resumable from a refresh or
     * a new device. Each active document type is its own step (in `sort`
     * order): adding one in Filament inserts a step, deactivating one
     * removes it, with no code change (CP1 acceptance box 6).
     *
     * @return array{name: string, documentType?: DocumentType}
     */
    private function currentStep(User $user, TutorProfile $profile): array
    {
        // Locked states: nothing left to edit. `draft` and
        // `changes_requested` both fall through to normal derivation below —
        // a changes_requested profile already has every field filled from
        // its original submission, so it naturally resolves to `complete`,
        // showing the tutor their existing data plus the admin's review
        // note, editable exactly as during `draft` (cycle 02 r3, sub-cycle 1c).
        if (in_array($profile->status, [
            TutorProfileStatus::PendingReview,
            TutorProfileStatus::Approved,
            TutorProfileStatus::Rejected,
            TutorProfileStatus::Suspended,
        ], true)) {
            return ['name' => 'submitted'];
        }

        if ($user->phone === null) {
            return ['name' => 'personal'];
        }

        if ($profile->permit_number === null || $profile->permit_expires_at === null) {
            return ['name' => 'permit'];
        }

        // A rejected document does not count as uploaded (R28): it returns a
        // changes_requested tutor to that type's step, where storeDocument()
        // soft-deletes the rejected row and creates a fresh pending one.
        $uploadedTypeIds = $profile->tutorDocuments()
            ->where('status', '!=', TutorDocumentStatus::Rejected)
            ->pluck('document_type_id');

        $nextType = DocumentType::query()->active()->orderBy('sort')
            ->whereNotIn('id', $uploadedTypeIds)
            ->first();

        if ($nextType !== null) {
            return ['name' => 'document', 'documentType' => $nextType];
        }

        if ($profile->bank_name === null || $profile->bank_account_name === null || $profile->bank_iban === null) {
            return ['name' => 'bank'];
        }

        if ($profile->tutorSubjects()->doesntExist()) {
            return ['name' => 'subjects'];
        }

        if ($profile->hourly_rate === null) {
            return ['name' => 'rate'];
        }

        if ($profile->headline === null || $profile->bio === null) {
            return ['name' => 'profile'];
        }

        if ($profile->availabilityRules()->doesntExist()) {
            return ['name' => 'availability'];
        }

        if ($profile->agreement_accepted_at === null) {
            return ['name' => 'agreement'];
        }

        return ['name' => 'complete'];
    }

    /**
     * Refuses a step handler unless the step it's for is at or before the
     * wizard's own derived position, so a request can't skip ahead. Once the
     * profile has left `draft` (submitted for review), every step is
     * refused outright — re-entry via `changes_requested` is 1c's scope.
     * Closes cycle 01 review note #8.
     */
    private function guardStepNotAhead(User $user, TutorProfile $profile, string $step): void
    {
        $current = $this->currentStep($user, $profile)['name'];

        abort_if($current === 'submitted', 409);

        $currentIndex = array_search($current, self::STEP_ORDER, true);
        $stepIndex = array_search($step, self::STEP_ORDER, true);

        if ($currentIndex === false || $stepIndex === false) {
            return;
        }

        abort_if($stepIndex > $currentIndex, 409);
    }

    /**
     * Each curriculum the tutor teaches at their highest level tier, mapped
     * to its current `price_bands` row (latest `effective_from` not in the
     * future) or `null` if that curriculum has none — never hard-coded, so
     * an admin edit to the bands takes effect immediately, and a missing
     * band is surfaced rather than silently skipped (R27). Empty until the
     * tutor has at least one subject.
     *
     * @return Collection<int, array{curriculum: Curriculum, band: PriceBand|null}>
     */
    private function currentBandsFor(TutorProfile $profile): Collection
    {
        $tutorSubjects = $profile->tutorSubjects()->get(['curriculum_id', 'level_tier']);

        if ($tutorSubjects->isEmpty()) {
            return collect();
        }

        $highestTier = $tutorSubjects->pluck('level_tier')
            ->sortByDesc(fn (LevelTier $tier) => $tier->rank())
            ->first();

        $curriculumIds = $tutorSubjects->where('level_tier', $highestTier)->pluck('curriculum_id')->unique();

        /** @var Collection<int, array{curriculum: Curriculum, band: PriceBand|null}> $entries */
        $entries = Curriculum::query()->whereIn('id', $curriculumIds)->get()
            ->map(fn (Curriculum $curriculum) => [
                'curriculum' => $curriculum,
                'band' => PriceBand::query()
                    ->where('curriculum_id', $curriculum->id)
                    ->where('level_tier', $highestTier)
                    ->where('effective_from', '<=', now()->toDateString())
                    ->orderByDesc('effective_from')
                    ->first(),
            ])
            ->values();

        return $entries;
    }

    /**
     * R26/R27: a tutor's single `hourly_rate` must satisfy every curriculum
     * they teach at their highest tier — the intersection of each
     * curriculum's band, never the union (a union would accept a rate
     * outside one curriculum's real band). `conflicting` lists curricula by
     * name, never silently picking one, when: their bands don't overlap at
     * all (`min` ends up above `max`), or a curriculum has no current band
     * at all (R27 — previously silently dropped from the calculation).
     * Null only when the tutor has no subjects yet.
     *
     * @return array{min: int, max: int, conflicting: array<int, string>}|null
     */
    private function rateBandFor(TutorProfile $profile): ?array
    {
        $entries = $this->currentBandsFor($profile);

        if ($entries->isEmpty()) {
            return null;
        }

        $missing = $entries->filter(fn (array $entry) => $entry['band'] === null)
            ->map(fn (array $entry) => $entry['curriculum']->name);

        if ($missing->isNotEmpty()) {
            return ['min' => 0, 'max' => 0, 'conflicting' => $missing->values()->all()];
        }

        $min = $entries->max(fn (array $entry) => $entry['band']->min_rate->toFils());
        $max = $entries->min(fn (array $entry) => $entry['band']->max_rate->toFils());

        return [
            'min' => $min,
            'max' => $max,
            'conflicting' => $min > $max
                ? $entries->map(fn (array $entry) => $entry['curriculum']->name)->all()
                : [],
        ];
    }

    /**
     * R27: a subjects change can move the tutor's highest tier or
     * curriculum set, so a previously valid `hourly_rate` may no longer
     * fit. Rather than leaving a stale rate in place — which let a tutor
     * reach `complete` with a rate outside their real band — clear it so
     * `currentStep()` sends them back through `rate` with the correct band.
     */
    private function invalidateRateIfOutOfBand(TutorProfile $profile): void
    {
        if ($profile->hourly_rate === null) {
            return;
        }

        $band = $this->rateBandFor($profile);
        $rateFils = $profile->hourly_rate->toFils();

        if ($band === null || $band['conflicting'] !== [] || $rateFils < $band['min'] || $rateFils > $band['max']) {
            $profile->update(['hourly_rate' => null]);
        }
    }
}
