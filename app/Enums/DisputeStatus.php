<?php

namespace App\Enums;

enum DisputeStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
