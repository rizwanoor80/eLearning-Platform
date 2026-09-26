<?php

namespace App\Services\Video;

use RuntimeException;

/**
 * A provider call failed. Messages carry the provider name and HTTP status only — never a request
 * body, a token or a response body, so an exception is safe to log and to report.
 */
class VideoProviderException extends RuntimeException {}
