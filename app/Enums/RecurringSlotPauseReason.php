<?php

namespace App\Enums;

enum RecurringSlotPauseReason: string
{
    case PaymentFailed = 'payment_failed';
    case Admin = 'admin';

    /** R138: the tutor was suspended (Safeguarding queue or the automatic 3-strikes path). */
    case TutorSuspended = 'tutor_suspended';

    /** R138: the account (parent or tutor) was suspended from the Safeguarding queue. */
    case AccountSuspended = 'account_suspended';
}
