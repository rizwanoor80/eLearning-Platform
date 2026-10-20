<?php

namespace App\Enums;

/**
 * Where one tutor's line in a payout batch stands (23h, R251). `pending` is the tutor's "processing" money; `paid`
 * needs an external reference and has its `payout` ledger entries; `failed` returns the money to available. A line
 * leaves `pending` once and never changes again.
 */
enum PayoutLineStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Processing',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
        };
    }
}
