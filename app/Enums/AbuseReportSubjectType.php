<?php

namespace App\Enums;

/**
 * What an `abuse_reports` row is filed against (R137). No Eloquent `morphTo()` and no
 * Laravel morph map exist in this codebase (confirmed: no `morphMap`/`enforceMorphMap`
 * call anywhere) — `AbuseReport::reportedUser()` resolves the reported party at read
 * time from this value with a plain `match`, never a stored polymorphic FK.
 */
enum AbuseReportSubjectType: string
{
    case TutorProfile = 'tutor_profile';
    case User = 'user';
    case Lesson = 'lesson';
    case Conversation = 'conversation';
}
