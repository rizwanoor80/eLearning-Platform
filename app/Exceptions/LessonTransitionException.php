<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by `LessonStateMachine` when an edge is not in its table (invariant #2).
 */
class LessonTransitionException extends RuntimeException {}
