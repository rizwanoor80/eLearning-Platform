<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A `PaymentGateway::capture()` call failed. `BookLesson` catches this to
 * transition the lesson to `expired` instead of `confirmed` —
 * `LessonStateMachine::edges()` only allows `pending_payment -> [confirmed,
 * expired]`; `cancelled_payment_failed` is reachable only from `reserved`,
 * which `BookLesson` never creates.
 */
class PaymentCaptureException extends RuntimeException {}
