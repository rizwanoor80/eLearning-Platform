<?php

namespace App\Enums;

enum Role: string
{
    case AccountOwner = 'account_owner';
    case Tutor = 'tutor';
    case Admin = 'admin';
}
