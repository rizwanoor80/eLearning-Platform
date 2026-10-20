<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * `MarkPayoutLinePaid` and `MarkPayoutLineFailed` throw this for every refusal an admin can act on: the line is no longer
 * pending, no payment reference was given, or the tutor no longer has the money to cover the line (a dispute opened after
 * the batch was made). The message is shown to the admin as it stands. `LedgerException` is the ledger's own refusal
 * and is not turned into this.
 */
class PayoutException extends RuntimeException {}
