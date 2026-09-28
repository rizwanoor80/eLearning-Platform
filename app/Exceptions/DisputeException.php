<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * `OpenDispute` throws this for every rejectable input: the caller is not
 * the lesson's account holder, the lesson is not `completed`/
 * `completed_reported`, the 48h window (R150) has passed, or a dispute
 * already exists for this lesson. `LessonTransitionException` (thrown by the
 * state machine itself) is not expected on this edge in practice — the
 * status check above runs first and covers the same ground with a clearer
 * message — but is not caught here, following `CancelLesson`'s precedent.
 */
class DisputeException extends RuntimeException {}
