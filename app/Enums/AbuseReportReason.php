<?php

namespace App\Enums;

/**
 * Why a report was filed (R137). Shown to the filer as a select and to admins in the
 * Safeguarding queue; `label()` exists because `contact_sharing` isn't presentable as-is.
 */
enum AbuseReportReason: string
{
    case Safety = 'safety';
    case ContactSharing = 'contact_sharing';
    case Conduct = 'conduct';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Safety => 'Safety concern',
            self::ContactSharing => 'Sharing contact details off-platform',
            self::Conduct => 'Inappropriate conduct',
            self::Other => 'Other',
        };
    }

    /**
     * The report-button select's options, shared by all three filing pages (R137) — one source
     * of truth rather than repeating the `array_map` at each of the three call sites.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $reason): array => ['value' => $reason->value, 'label' => $reason->label()], self::cases());
    }
}
