<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A progress report the rules refuse: not the lesson's tutor, a lesson that is not waiting for one,
 * one already filed, or trial fields that do not match the lesson's own type. The wrong lesson status
 * that a race produces is `LessonTransitionException`'s job, as everywhere else.
 */
class ProgressReportException extends RuntimeException {}
