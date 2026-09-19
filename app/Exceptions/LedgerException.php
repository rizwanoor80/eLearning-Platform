<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by `LedgerService` when an operation's precondition or the zero-sum
 * invariant fails; the surrounding transaction is rolled back (invariant #1).
 */
class LedgerException extends RuntimeException {}
