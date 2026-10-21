<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * `RequeueFailedRefund` throws this for a refusal an admin can act on (the attempt is no longer the latest, or did not fail).
 * The message is shown to the admin as it stands.
 */
class RefundRequeueException extends RuntimeException {}
