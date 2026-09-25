<?php

namespace App\Enums;

enum RecurringSlotSkipReason: string
{
    case LessonCollision = 'lesson_collision';
    case TutorBlocked = 'tutor_blocked';
    case TutorUnavailable = 'tutor_unavailable';
}
