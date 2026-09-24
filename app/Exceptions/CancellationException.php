<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * `CancelLesson`/`SkipLesson` throw this for every rejectable input: the
 * caller is neither the lesson's tutor nor its parent, or the lesson has
 * already started. `LessonTransitionException` (thrown by the state machine
 * itself) covers the wrong-status case, e.g. cancelling an already-cancelled
 * lesson.
 */
class CancellationException extends RuntimeException {}
