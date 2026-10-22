<?php

namespace App\Services\ErrorTracking;

/**
 * R276: the one rule for "may this environment send". A blank DSN is no DSN, and the silent environments never have one,
 * whatever ERROR_TRACKING_DSN holds. Called from config/error-tracking.php, so the answer is cached with the config.
 */
final class ErrorTrackingDsn
{
    public const SILENT_ENVIRONMENTS = ['local', 'testing'];

    public static function resolve(mixed $dsn, mixed $environment): ?string
    {
        $dsn = is_string($dsn) ? trim($dsn) : '';

        if ($dsn === '' || in_array($environment, self::SILENT_ENVIRONMENTS, true)) {
            return null;
        }

        return $dsn;
    }
}
