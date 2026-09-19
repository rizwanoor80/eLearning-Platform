<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by the admin tutor actions when the profile's current status does
 * not allow the requested transition. The table of allowed edges is
 * `App\Services\Tutors\TutorStatusTransitions` (the analogue of invariant #2's
 * lesson state machine); every tutor-status action asserts through it.
 */
class TutorStatusTransitionException extends RuntimeException {}
