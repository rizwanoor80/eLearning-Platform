<?php

namespace App\Enums;

enum TutorProfileStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
}
