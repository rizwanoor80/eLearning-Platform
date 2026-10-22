<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * `ReleaseOrphanedPendingPayment` throws this when a pending payment is not released: it is not an orphan, the
 * gateway cannot be asked right now, or the gateway says the attempt is still in flight. The message is safe to
 * show an admin and carries no card or parent detail.
 */
class OrphanedPaymentException extends RuntimeException {}
