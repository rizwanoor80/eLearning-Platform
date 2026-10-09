<?php

namespace App\Http\Controllers\Tutor;

use App\Actions\Tutor\CompleteTutorOnboarding;
use App\Actions\Tutor\ResetPermitScanOnPermitChange;
use App\Actions\Tutor\SaveTutorAvailability;
use App\Actions\Tutor\SaveTutorSubjects;
use App\Actions\Tutor\SetTutorRate;
use App\Enums\TutorProfileStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tutor\Onboarding\AgreementStepRequest;
use App\Http\Requests\Tutor\Onboarding\AvailabilityStepRequest;
use App\Http\Requests\Tutor\Onboarding\BankStepRequest;
use App\Http\Requests\Tutor\Onboarding\DocumentStepRequest;
use App\Http\Requests\Tutor\Onboarding\LinkedinStepRequest;
use App\Http\Requests\Tutor\Onboarding\PermitStepRequest;
use App\Http\Requests\Tutor\Onboarding\PersonalStepRequest;
use App\Http\Requests\Tutor\Onboarding\ProfileStepRequest;
use App\Http\Requests\Tutor\Onboarding\RateStepRequest;
use App\Http\Requests\Tutor\Onboarding\SubjectsStepRequest;
use App\Models\Curriculum;
use App\Models\DocumentType;
use App\Models\Page;
use App\Models\Subject;
use App\Models\TutorDocument;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Scheduling\BookingLeadTime;
use App\Services\Tutors\TutorRateBands;
use App\Services\Tutors\TutorSubmissionReadiness;
use App\Support\Money;
use App\Support\YearGroups\YearGroupOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class TutorOnboardingController extends Controller
{
    /**
     * R171: only these three gate the wizard's derived position (name/country/timezone,
     * CV-or-LinkedIn, the agreement) — submission needs nothing else. Permit, other documents,
     * bank, subjects, rate, profile and availability are all optional at submission and reachable
     * at any time once the profile is editable; `guardStepNotAhead()` never refuses them. Used
     * only to refuse a request for a mandatory step later than the wizard's own derived position
     * (never to derive the step itself, which always comes from saved state). `storeDocument()`
     * (for the `cv` type) and `storeLinkedin()` are the one case that *is* listed here (`document`)
     * but never call `guardStepNotAhead()`: both jointly satisfy the same gate, so either must be
     * reachable regardless of the wizard's derived position, same as the other optional steps —
     * only the `submitted` lock-out applies to them.
     *
     * @var array<int, string>
     */
    private const MANDATORY_STEPS = ['personal', 'document', 'agreement'];

    /**
     * R187(a): the words a tutor sees when a step is refused because the profile is no longer
     * editable — shown as a message on the same page (bootstrap/app.php), never as an error page.
     */
    private const LOCKED = 'Your profile is with the review team, so this section is locked for now.';

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $step = $this->currentStep($user, $profile);

        $agreementPage = Page::query()->where('slug', 'tutor_agreement')->first();
        $leadTime = app(BookingLeadTime::class);

        return Inertia::render('tutor/Onboarding', [
            'step' => $step['name'],
            'currentDocumentType' => $step['documentType'] ?? null,
            'status' => $profile->status->value,
            // The admin's note is shown for changes_requested (what to fix) and
            // suspended (why) — SuspendTutor promises the tutor sees it (R31).
            'reviewNote' => in_array($profile->status, [TutorProfileStatus::ChangesRequested, TutorProfileStatus::Suspended], true)
                ? $profile->review_note
                : null,
            // R185: the sections the admin ticked on "Request changes" — only while the request is open.
            'reviewSections' => $profile->status === TutorProfileStatus::ChangesRequested
                ? ($profile->review_sections ?? [])
                : [],
            // R171: everything but the three mandatory steps is reachable at any time once the
            // profile is still editable — the picker UI already built for changes_requested
            // extends to draft too (12a). Not gated on `step === 'complete'`: R170's UAE permit
            // note and R173(b)'s "Skip for now" both need to be visible from the start, not only
            // after every mandatory step is already done.
            // R185: an approved tutor can reach only the availability form (to become listed).
            'canEditAvailability' => $profile->status === TutorProfileStatus::Approved,
            'canPickSteps' => in_array($profile->status, [TutorProfileStatus::Draft, TutorProfileStatus::ChangesRequested], true),
            'missingForSubmission' => app(TutorSubmissionReadiness::class)->missing($user, $profile),
            'personal' => [
                'country' => $profile->country,
                'phone' => $user->phone,
                'timezone' => $user->timezone,
                'display_name' => $profile->display_name,
                'default_display_name' => $profile->defaultDisplayName(),
            ],
            'profile' => [
                'permit_number' => $profile->permit_number,
                'permit_expires_at' => $profile->permit_expires_at?->toDateString(),
                'linkedin_url' => $profile->linkedin_url,
                'bank_name' => $profile->bank_name,
                'bank_account_name' => $profile->bank_account_name,
                'bank_iban_masked' => $profile->bankIbanMasked(),
                'bank_swift' => $profile->bank_swift,
                'headline' => $profile->headline,
                'bio' => $profile->bio,
                'intro_video_url' => $profile->intro_video_url,
                'hourly_rate' => $profile->hourly_rate?->toFils(),
                // The effective value (R179): what a parent is held to today, so the select never shows
                // a choice the platform would not honour.
                'min_lead_hours' => $leadTime->for($profile),
            ],
            'leadTimeOptions' => array_map(
                fn (int $hours): array => ['value' => $hours, 'label' => $leadTime->label($hours)],
                $leadTime->options(),
            ),
            'documentTypes' => DocumentType::query()->active()->orderBy('sort')
                ->get(['id', 'code', 'name', 'description']),
            'documents' => $profile->tutorDocuments()
                ->get(['id', 'document_type_id', 'original_name', 'status']),
            'curricula' => Curriculum::query()->orderBy('sort')->get(['id', 'code', 'name']),
            'subjects' => Subject::query()->orderBy('sort')->get(['id', 'name']),
            'tutorSubjects' => $profile->tutorSubjects()
                ->get(['id', 'curriculum_id', 'subject_id', 'level_min_id', 'level_max_id', 'level_min_legacy', 'level_max_legacy', 'level_tier']),
            'yearGroups' => YearGroupOptions::all(),
            'rateBand' => $this->rateBandFor($profile),
            // The one place this is computed (R53): `TutorProfile::trialPrice()`,
            // the same method `BookLesson` freezes onto the lesson. Reflects the
            // saved rate only — there is no live-as-you-type preview of an
            // unsaved rate, so this updates after the rate step is submitted.
            'trialPriceFils' => $profile->trialPrice()?->toFils(),
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

        $validated = $request->validated();

        // `country` lives on the tutor profile (R170), not the user — phone and timezone stay on
        // the user as before.
        $user->update([
            'phone' => $validated['phone'] ?? null,
            'timezone' => $validated['timezone'],
        ]);
        $displayName = trim((string) ($validated['display_name'] ?? ''));
        $profile->update([
            'country' => strtoupper((string) $validated['country']),
            'display_name' => $displayName === '' ? null : $displayName,
        ]);

        return redirect()->route('tutor.onboarding');
    }

    public function storePermit(PermitStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $this->guardStepNotAhead($user, $profile, 'permit');

        // Compared by value, the expiry by DATE: a datetime string for the same day is
        // not a change (`isDirty` on the cast attribute would say it is).
        $oldNumber = $profile->permit_number;
        $oldExpiry = $profile->permit_expires_at?->toDateString();

        $profile->fill($request->validated());
        $permitChanged = $profile->permit_number !== $oldNumber
            || $profile->permit_expires_at?->toDateString() !== $oldExpiry;

        DB::transaction(function () use ($profile, $permitChanged): void {
            $profile->save();

            // R36 (g): an accepted scan vouches for the old number and date.
            if ($permitChanged) {
                app(ResetPermitScanOnPermitChange::class)($profile);
            }
        });

        return redirect()->route('tutor.onboarding');
    }

    public function storeDocument(DocumentStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);

        // R171: every active document type is reachable at any time (not just the one
        // `currentStep()` is currently waiting on), so the client says which type it means —
        // validated against `active` types only (`DocumentStepRequest`); the only ordering rule
        // left is that the profile must still be editable.
        abort_if($this->currentStep($user, $profile)['name'] === 'submitted', 409, self::LOCKED);

        $documentType = DocumentType::query()->active()->where('id', $request->validated('document_type_id'))->firstOrFail();

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $path = $file->store('tutor-documents/'.$profile->id, 'local');
        abort_if($path === false, 500, 'The document could not be stored.');

        try {
            DB::transaction(function () use ($profile, $documentType, $file, $path): void {
                $profile->tutorDocuments()->where('document_type_id', $documentType->id)->delete();

                TutorDocument::query()->create([
                    'tutor_profile_id' => $profile->id,
                    'document_type_id' => $documentType->id,
                    'disk_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                ]);
            });
        } catch (Throwable $e) {
            // The file was written before the transaction; no row points at it now (R36 d).
            Storage::disk('local')->delete($path);

            throw $e;
        }

        return redirect()->route('tutor.onboarding');
    }

    public function storeLinkedin(LinkedinStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        abort_if($this->currentStep($user, $profile)['name'] === 'submitted', 409, self::LOCKED);

        $profile->update(['linkedin_url' => $request->validated('linkedin_url')]);

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

    public function storeSubjects(SubjectsStepRequest $request, SaveTutorSubjects $saveTutorSubjects): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $this->guardStepNotAhead($user, $profile, 'subjects');

        /** @var array<int, array{curriculum_id: int, subject_id: int, level_min_id: int, level_max_id: int}> $subjectsInput */
        $subjectsInput = $request->validated('subjects');

        $saveTutorSubjects($profile, $subjectsInput);

        return redirect()->route('tutor.onboarding');
    }

    public function storeRate(RateStepRequest $request, SetTutorRate $setTutorRate): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $this->guardStepNotAhead($user, $profile, 'rate');

        $rate = Money::fromDecimalString((string) $request->validated('hourly_rate'));

        $setTutorRate($profile, $rate);

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
        // R185: an approved tutor is locked out of every other step, but may still add or change
        // their weekly windows — that is the only way to become listed (the dashboard banner's link).
        if ($profile->status !== TutorProfileStatus::Approved) {
            $this->guardStepNotAhead($user, $profile, 'availability');
        }

        /** @var array<int, array{weekday: int, start_time: string, end_time: string}> $rulesInput */
        $rulesInput = $request->validated('rules');

        /** @var array<int, array{date: string, start_time: string, end_time: string, type: string}> $exceptionsInput */
        $exceptionsInput = $request->validated('exceptions') ?? [];

        app(SaveTutorAvailability::class)($profile, $rulesInput, $exceptionsInput);

        return redirect()->route('tutor.onboarding');
    }

    public function storeAgreement(AgreementStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $this->guardStepNotAhead($user, $profile, 'agreement');

        // The version the tutor was shown (validated against the current one by
        // the request), not a fresh read that a publish could have moved.
        $profile->update([
            'agreement_accepted_at' => now(),
            'agreement_version' => $request->integer('version'),
        ]);

        return redirect()->route('tutor.onboarding');
    }

    public function complete(Request $request, CompleteTutorOnboarding $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);

        $current = $this->currentStep($user, $profile)['name'];
        abort_if($current === 'submitted', 409, 'Your profile has already been submitted for review.');
        abort_unless($current === 'complete', 409, 'Finish the required steps first, then submit for review.');

        // Defence in depth (R27): completion re-checks the stored rate
        // against the *current* band rather than trusting that it was
        // still valid when saved — SaveTutorSubjects::invalidateRateIfOutOfBand()
        // already clears a stale rate on every subjects change, so this should
        // never actually fire, but a 409 here is cheap insurance against
        // any other path that could leave hourly_rate stale. R171 moved
        // subjects/rate to the approval minimum, not the submission one, so
        // a tutor with no rate yet (CV-or-LinkedIn-only) must still be able
        // to submit — only a rate that *is* set gets re-validated here.
        if ($profile->hourly_rate !== null) {
            $band = $this->rateBandFor($profile);
            $rateFils = $profile->hourly_rate->toFils();
            abort_if(
                $band === null || $band['conflicting'] !== []
                    || $rateFils < $band['min'] || $rateFils > $band['max'],
                409,
                'Your rate no longer fits the price band for your subjects. Please update it, then submit again.',
            );
        }

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
     * The current step is derived server-side from what's actually saved — never from client
     * input — so the wizard is resumable from a refresh or a new device. R171 shrank what gates
     * this derivation to the three steps submission actually requires (`MANDATORY_STEPS`):
     * personal (country + timezone — not phone, dropped to optional per the advisor ruling),
     * CV-or-LinkedIn, and the agreement. Everything else (permit, other documents, bank, subjects,
     * rate, profile, availability) is optional at submission and reachable at any time, once
     * `complete` is first reached, through the same picker UI already built for
     * `changes_requested` (`canPickSteps` in `show()`).
     *
     * @return array{name: string, documentType?: DocumentType|null}
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

        // `users.timezone` is never null (non-nullable column, defaults to 'Asia/Dubai'), so only
        // `country` can actually gate this step.
        if ($profile->country === null) {
            return ['name' => 'personal'];
        }

        // A rejected CV does not count (R28, same convention as every other document type): it
        // returns a draft or changes_requested tutor to this step, where storeDocument()
        // soft-deletes the rejected row and creates a fresh pending one — unless a LinkedIn URL
        // already satisfies the requirement on its own.
        if (! $profile->hasCvOrLinkedin()) {
            $cvType = DocumentType::query()->active()->where('code', DocumentType::CV_CODE)->first();

            return ['name' => 'document', 'documentType' => $cvType];
        }

        if ($profile->agreement_accepted_at === null) {
            return ['name' => 'agreement'];
        }

        return ['name' => 'complete'];
    }

    /**
     * Refuses a mandatory-step handler unless the step it's for is at or before the wizard's own
     * derived position, so a request can't skip ahead of `personal` -> `document` -> `agreement`.
     * Every other step name is optional (R171) and always allowed while the profile is still
     * editable — only the `submitted` lock-out applies to them. Once the profile has left `draft`
     * (submitted for review), every step is refused outright — re-entry via `changes_requested` is
     * 1c's scope. Closes cycle 01 review note #8.
     */
    private function guardStepNotAhead(User $user, TutorProfile $profile, string $step): void
    {
        $current = $this->currentStep($user, $profile)['name'];

        abort_if($current === 'submitted', 409, self::LOCKED);

        if (! in_array($step, self::MANDATORY_STEPS, true)) {
            return;
        }

        $currentIndex = array_search($current, self::MANDATORY_STEPS, true);
        $stepIndex = array_search($step, self::MANDATORY_STEPS, true);

        if ($currentIndex === false || $stepIndex === false) {
            return;
        }

        abort_if($stepIndex > $currentIndex, 409, 'Please finish the earlier step first.');
    }

    /**
     * @return array{min: int, max: int, conflicting: array<int, string>}|null
     */
    private function rateBandFor(TutorProfile $profile): ?array
    {
        return app(TutorRateBands::class)->bandFor($profile);
    }
}
