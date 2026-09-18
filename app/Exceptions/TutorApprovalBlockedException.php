<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ApproveTutor when a required document type still lacks an
 * accepted document (CP1 acceptance). Callers in a Filament action context
 * catch this and show a notification; callers in tests assert it's thrown.
 */
class TutorApprovalBlockedException extends RuntimeException {}
