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
 *
 * The review of the same cycle added two more refusals, because the file is read by a bank's parser as well as by a
 * spreadsheet: bytes that are not valid UTF-8 (a regex with `/u` fails on them, which must never read as "safe", and
 * the payout line's JSON details could not be stored) and control characters, line breaks included, which would let a
 * name run on into a second payment row.
 */
class NotSpreadsheetFormula implements ValidationRule
{
    public const MESSAGE = 'This cannot start with =, +, - or @, and cannot contain line breaks or control characters.';

    public static function startsLikeFormula(?string $value): bool
    {
        // `!== 0`, not `=== 1`: preg_match returns false on invalid UTF-8, and that is unsafe, not clean.
        return $value !== null && preg_match('/^[\p{Z}\p{Cf}\s]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $value) !== 0;
    }

    /** Anything a bank file cannot carry: a formula start, invalid UTF-8, or a control character anywhere. */
    public static function isUnsafe(?string $value): bool
    {
        return $value !== null
            && (self::startsLikeFormula($value) || preg_match('/\p{Cc}/u', $value) !== 0);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::isUnsafe($value)) {
            $fail(self::MESSAGE);
        }
    }
}
