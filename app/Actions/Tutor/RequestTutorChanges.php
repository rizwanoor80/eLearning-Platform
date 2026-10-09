<?php

namespace App\Actions\Tutor;

use App\Actions\RecordAuditLog;
use App\Enums\TutorProfileStatus;
use App\Enums\TutorReviewSection;
use App\Events\Tutor\TutorChangesRequested;
use App\Exceptions\TutorStatusTransitionException;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Tutors\TutorStatusTransitions;
use InvalidArgumentException;

class RequestTutorChanges
{
    /**
     * `approved` is here for R36 (b): an admin can pull an approved tutor back
     * to `changes_requested` (not bookable, with an email naming what is
     * needed). `suspended` is not: a suspended tutor comes back only through
     * ReinstateTutor, even though the edge exists in the transitions table.
     */
    public const FROM = [
        TutorProfileStatus::PendingReview,
        TutorProfileStatus::ChangesRequested,
        TutorProfileStatus::Approved,
    ];

    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @param  array<int, TutorReviewSection|string>  $sections  the onboarding sections to fix (R185)
     */
    public function __invoke(User $admin, TutorProfile $profile, array $sections, ?string $note = null): void
    {
        $this->apply($admin, $profile, $sections, $note);

        TutorChangesRequested::dispatch($profile);
    }

    /**
     * The transition without the event, for a caller that owns a wider transaction
     * and dispatches after its own commit (ReviewTutorDocument).
     *
     * Sections are validated against the enum here, not only in the form: an unknown key is an
     * InvalidArgumentException, never stored. At least one section or a note is required, so a
     * request always tells the tutor what to do. Stored as a de-duplicated list in enum order.
     *
     * @param  array<int, TutorReviewSection|string>  $sections
     */
    public function apply(User $admin, TutorProfile $profile, array $sections, ?string $note = null): void
    {
        $keys = self::normalise($sections);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($keys === [] && $note === null) {
            throw new InvalidArgumentException('Name at least one section or write a note.');
        }

        TutorStatusTransitions::transition(
            $profile,
            TutorProfileStatus::ChangesRequested,
            'Changes can only be requested on a submitted or approved profile.',
            function (TutorProfile $profile) use ($admin, $keys, $note) {
                if (! in_array($profile->status, self::FROM, true)) {
                    throw new TutorStatusTransitionException('Changes can only be requested on a submitted or approved profile.');
                }

                $before = ['status' => $profile->status->value];

                $profile->forceFill([
                    'status' => TutorProfileStatus::ChangesRequested,
                    'review_note' => $note,
                    'review_sections' => $keys === [] ? null : $keys,
                ])->save();

                ($this->recordAuditLog)($admin, 'tutor.changes_requested', $profile, $before, [
                    'status' => TutorProfileStatus::ChangesRequested->value,
                    'review_note' => $note,
                    'review_sections' => $keys,
                ]);
            },
        );
    }

    /**
     * @param  array<int, TutorReviewSection|string>  $sections
     * @return array<int, string> enum values, unique, in enum order
     *
     * @throws InvalidArgumentException for a key that is not a TutorReviewSection
     */
    public static function normalise(array $sections): array
    {
        $chosen = [];

        foreach ($sections as $section) {
            $enum = $section instanceof TutorReviewSection ? $section : TutorReviewSection::tryFrom((string) $section);

            if ($enum === null) {
                throw new InvalidArgumentException('Unknown review section.');
            }

            $chosen[$enum->value] = true;
        }

        return array_values(array_filter(
            array_map(fn (TutorReviewSection $case): string => $case->value, TutorReviewSection::cases()),
            fn (string $value): bool => isset($chosen[$value]),
        ));
    }
}
