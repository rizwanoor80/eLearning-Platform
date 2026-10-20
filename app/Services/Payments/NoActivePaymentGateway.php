<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * There is no active, usable `payment_gateways` row. The app still runs (PRD §12); the caller
 * shows an admin notice and creates no `payments` row.
 */
class NoActivePaymentGateway extends RuntimeException {}
