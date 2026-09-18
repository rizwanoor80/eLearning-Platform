<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by the admin tutor actions when the profile's current status does
 * not allow the requested transition. The tutor-status transition table
 * (enforced in the Actions, the analogue of invariant #2's lesson state
 * machine):
 *
 *   draft            -> pending_review     (tutor, CompleteTutorOnboarding)
 *   changes_requested-> pending_review     (tutor, CompleteTutorOnboarding)
 *   pending_review   -> approved           (admin, ApproveTutor)
 *   pending_review | changes_requested -> rejected | changes_requested (admin)
 *   approved         -> suspended          (admin, SuspendTutor)
 */
class TutorStatusTransitionException extends RuntimeException {}
