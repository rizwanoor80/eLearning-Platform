<?php

namespace App\Enums;

enum TutorDocumentStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
