<?php

namespace App\Enums;

/**
 * Which side of the lesson a join token or an attendance event belongs to. The learner side is
 * joined by the account holder on the learner's behalf — a minor never has a login (invariant 7).
 */
enum VideoParticipant: string
{
    case Tutor = 'tutor';
    case Learner = 'learner';
}
