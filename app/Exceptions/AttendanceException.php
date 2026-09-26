<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A join, an "I've joined" or a no-show mark that the rules refuse: the wrong person, the wrong
 * time, the wrong provider mode, or a party who did (or did not) join. The wrong lesson status is
 * `LessonTransitionException`'s job, as everywhere else.
 */
class AttendanceException extends RuntimeException {}
