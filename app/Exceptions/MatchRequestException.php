<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A match-request action refused: a status the transition table does not
 * allow, or a suggestion list that breaks the 1–3 bookable-tutor rule.
 */
class MatchRequestException extends RuntimeException {}
