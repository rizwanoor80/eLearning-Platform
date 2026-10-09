<?php

namespace App\Services\Tutors;

use App\Enums\TutorDocumentStatus;
use App\Enums\TutorProfileStatus;
use App\Models\DocumentType;
use App\Models\TutorProfile;
use App\Models\User;

/**
 * R186: the tutor's onboarding checklist, in three groups — what is needed to submit for review,
 * what is needed to appear in search, and what is optional. Every tick is read from the same
 * checks the server enforces (`TutorSubmissionReadiness` for submission; `TutorRateBands`,
 * `TutorProfile::hasAllRequiredDocumentsAccepted()` / `permitAllowsBooking()` and the
 * availability rows behind `TutorProfile::bookable()` for search), never a second definition, so
 * a tick cannot say done where the server would refuse. Shown on the onboarding page, condensed
 * on the tutor dashboard, and on the admin review page.
 *
 * `step` is the onboarding section the item opens (a `PickableStep` or `agreement` in the page).
 */
class TutorOnboardingChecklist
{
    public const GROUP_SUBMIT = 'submit';

    public const GROUP_SEARCH = 'search';

    public const GROUP_OPTIONAL = 'optional';

    public function __construct(
        private TutorSubmissionReadiness $submission,
        private TutorRateBands $rates,
    ) {}

    /**
     * @return list<array{key: string, title: string, hint: string, required: bool, complete: bool, items: list<array{key: string, label: string, step: string, done: bool}>}>
     */
    public function groups(User $user, TutorProfile $profile): array
    {
        $met = $this->submission->met($user, $profile);

        $submit = [
            $this->item('contact', 'Contact details', 'personal', $met['name'] && $met['country']),
            $this->item('cv_or_linkedin', 'CV or LinkedIn profile', 'document', $met['cv_or_linkedin']),
            $this->item('agreement', 'Tutor agreement', 'agreement', $met['agreement']),
        ];

        $search = [
            $this->item('subjects', 'Subjects you teach', 'subjects', $profile->tutorSubjects()->exists()),
            $this->item('rate', 'Hourly rate', 'rate', $this->rates->problemWithRate($profile) === null),
            $this->item('availability', 'Weekly availability', 'availability', $profile->availabilityRules()->exists()),
        ];

        // Only when they apply: an admin can mark a document type required, and a permit can lapse —
        // either one blocks approval or listing, so it would be wrong to call it optional.
        if (DocumentType::query()->active()->where('required', true)->exists()) {
            $search[] = $this->item('required_documents', 'Required documents accepted', 'document', $profile->hasAllRequiredDocumentsAccepted());
        }

        if (! $profile->permitAllowsBooking()) {
            $search[] = $this->item('permit_valid', 'Work permit renewed', 'permit', false);
        }

        $optional = [
            $this->item('permit', 'Work permit', 'permit', $profile->permit_number !== null || $profile->permit_expires_at !== null),
            $this->item('documents', 'Other documents', 'document', $this->hasOtherDocument($profile)),
            $this->item('bank', 'Bank details', 'bank', $profile->bank_name !== null && $profile->bankIbanMasked() !== null),
            $this->item('bio', 'Bio and headline', 'profile', trim((string) $profile->headline) !== '' && trim((string) $profile->bio) !== ''),
            $this->item('intro_video', 'Intro video', 'profile', trim((string) $profile->intro_video_url) !== ''),
            $this->item('lead_time', 'Booking notice (lead time)', 'profile', $profile->min_lead_hours !== null),
        ];

        return [
            $this->group($profile, self::GROUP_SUBMIT, 'To submit for review', 'Needed before you can send your profile to our team.', true, $submit),
            $this->group($profile, self::GROUP_SEARCH, 'To appear in search', 'Needed before parents can find and book you.', true, $search),
            $this->group($profile, self::GROUP_OPTIONAL, 'Optional', 'Not needed to be reviewed or listed, but they help parents choose you.', false, $optional),
        ];
    }

    /**
     * Whether the first two groups are done — the dashboard shows the condensed checklist until then.
     */
    public function requiredDone(User $user, TutorProfile $profile): bool
    {
        foreach ($this->groups($user, $profile) as $group) {
            if ($group['required'] && ! $group['complete']) {
                return false;
            }
        }

        return true;
    }

    private function hasOtherDocument(TutorProfile $profile): bool
    {
        return $profile->tutorDocuments()
            ->where('status', '!=', TutorDocumentStatus::Rejected)
            ->whereHas('documentType', fn ($query) => $query->where('code', '!=', DocumentType::CV_CODE))
            ->exists();
    }

    /**
     * @return array{key: string, label: string, step: string, done: bool}
     */
    private function item(string $key, string $label, string $step, bool $done): array
    {
        return ['key' => $key, 'label' => $label, 'step' => $step, 'done' => $done];
    }

    /**
     * @param  list<array{key: string, label: string, step: string, done: bool}>  $items
     * @return array{key: string, title: string, hint: string, required: bool, complete: bool, items: list<array{key: string, label: string, step: string, done: bool, href?: null}>}
     */
    private function group(TutorProfile $profile, string $key, string $title, string $hint, bool $required, array $items): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'hint' => $hint,
            'required' => $required,
            'complete' => collect($items)->every(fn (array $item): bool => $item['done']),
            'items' => array_map(fn (array $item): array => $this->locked($profile, $item['step']) ? $item + ['href' => null] : $item, $items),
        ];
    }

    /**
     * Whether the onboarding page lets the tutor open this section now. The wizard is editable only
     * as a draft or when changes are requested; an approved tutor can still add availability (14a).
     * Anywhere else the item is a plain line (`href: null`), never a link to a locked page. A profile
     * that does not exist yet is a draft.
     */
    private function locked(TutorProfile $profile, string $step): bool
    {
        $status = $profile->status ?? TutorProfileStatus::Draft;

        if (in_array($status, [TutorProfileStatus::Draft, TutorProfileStatus::ChangesRequested], true)) {
            return false;
        }

        return ! ($status === TutorProfileStatus::Approved && $step === 'availability');
    }
}
