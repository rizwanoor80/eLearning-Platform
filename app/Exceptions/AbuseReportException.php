<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Filing an abuse report was refused (e.g. reporter no longer active). Caught by the
 * controller and turned into a toast — never a raw validation-error response.
 */
class AbuseReportException extends RuntimeException {}
