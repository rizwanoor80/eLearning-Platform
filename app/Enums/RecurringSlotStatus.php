<?php

namespace App\Enums;

enum RecurringSlotStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
}
