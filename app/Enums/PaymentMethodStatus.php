<?php

namespace App\Enums;

enum PaymentMethodStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Failed = 'failed';
}
