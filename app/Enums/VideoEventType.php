<?php

namespace App\Enums;

enum VideoEventType: string
{
    case Joined = 'joined';
    case Left = 'left';
}
