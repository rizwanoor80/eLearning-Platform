<?php

namespace App\Enums;

/**
 * The three outcomes of one `ops:preflight` check (R275). `Unreadable` is a failure too: a check that cannot be
 * evaluated never passes, but it is printed apart so an operator can tell a broken lookup from a wrong setting.
 */
enum PreflightStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Unreadable = 'unreadable';

    public function passed(): bool
    {
        return $this === self::Pass;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'PASS',
            self::Fail => 'FAIL',
            self::Unreadable => 'FAIL (unreadable)',
        };
    }
}
