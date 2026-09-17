<?php

namespace App\Enums;

enum AvailabilityExceptionType: string
{
    case Blocked = 'blocked';
    case Extra = 'extra';
}
