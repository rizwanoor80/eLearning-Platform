<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A review the rules refuse: not the lesson's account holder, a lesson that never went through
 * `completed` with both parties joined (R136), one already reviewed, or a comment `MessageMasker`
 * could not mask. The controller converts this to a redirect with a flash toast, never a 500 —
 * matching `ProgressReportException`'s pattern.
 */
class ReviewException extends RuntimeException {}
