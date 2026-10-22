<?php

namespace App\Enums;

/**
 * Lifecycle of an `account_deletion_requests` row (R273). `Open` is what a parent's or tutor's request starts
 * as; an admin moves it to `Erased` (the account was anonymised) or `Declined` (a note went to the user).
 */
enum AccountDeletionRequestStatus: string
{
    case Open = 'open';
    case Erased = 'erased';
    case Declined = 'declined';
}
