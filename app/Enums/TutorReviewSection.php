<?php

namespace App\Enums;

/**
 * The onboarding sections an admin can name when requesting changes (R185). The value is what is
 * stored in `tutor_profiles.review_sections` and sent to the onboarding page to highlight.
 */
enum TutorReviewSection: string
{
    case Contact = 'contact';
    case Permit = 'permit';
    case Documents = 'documents';
    case Bank = 'bank';
    case Subjects = 'subjects';
    case Rate = 'rate';
    case Bio = 'bio';
    case Availability = 'availability';

    public function label(): string
    {
        return match ($this) {
            self::Contact => 'Contact details',
            self::Permit => 'Work permit',
            self::Documents => 'Documents',
            self::Bank => 'Bank details',
            self::Subjects => 'Subjects',
            self::Rate => 'Hourly rate',
            self::Bio => 'Headline and bio',
            self::Availability => 'Availability',
        };
    }

    /**
     * @return array<string, string> value => label, for the admin checklist
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
