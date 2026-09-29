<?php

namespace App\Enums;

/**
 * DATA_MODEL §disputes. No `no_show_tutor`/`no_show_student` split: a
 * tutor no-show auto-advances `no_show_tutor -> refunded` (terminal) before
 * a dispute could ever open on it (see docs/CYCLE-LOG.md, the 9b defaults
 * DECISION) — `NoShow` here always means the account holder's own claim on
 * a `completed`/`completed_reported` lesson, party unspecified.
 */
enum DisputeReason: string
{
    case NoShow = 'no_show';
    case Quality = 'quality';
    case Technical = 'technical';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NoShow => 'A no-show',
            self::Quality => 'Quality of the lesson',
            self::Technical => 'A technical problem',
            self::Other => 'Other',
        };
    }

    /**
     * The dispute form's select (CP8, R150) — same shape as `AbuseReportReason::options()`.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $reason): array => ['value' => $reason->value, 'label' => $reason->label()], self::cases());
    }
}
