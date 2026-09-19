<?php

namespace App\Enums;

enum MatchRequestStatus: string
{
    case Open = 'open';
    case Suggested = 'suggested';
    case Closed = 'closed';
}
