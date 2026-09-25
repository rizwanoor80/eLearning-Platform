<?php

namespace App\Enums;

enum RecurringSlotPauseReason: string
{
    case PaymentFailed = 'payment_failed';
    case Admin = 'admin';
}
