<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 24e (R263): payout details end up in a file an admin uploads to a bank, and a spreadsheet runs a cell that starts with
 * `=`, `+`, `-` or `@` as a formula. Such a value is refused where it is typed instead of being altered on the way out
 * (a `'` prefix would reach the bank as part of the name). Only the first visible character is tested, so Persian and
 * Arabic names and real IBAN / SWIFT values are untouched. Leading spaces, no-break spaces and invisible format
 * characters (bidi marks) are skipped, and the full-width look-alikes are covered.
 */
class NotSpreadsheetFormula implements ValidationRule
{
    public static function startsLikeFormula(?string $value): bool
    {
        return $value !== null && preg_match('/^[\p{Z}\p{Cf}\s]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $value) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::startsLikeFormula($value)) {
            $fail('This cannot start with =, +, - or @.');
        }
    }
}
