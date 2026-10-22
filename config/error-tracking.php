<?php

use App\Services\ErrorTracking\ErrorTrackingDsn;

/*
|--------------------------------------------------------------------------
| Error tracking (R276)
|--------------------------------------------------------------------------
|
| Sentry (`sentry/sentry-laravel`), inert until a DSN is supplied. `dsn` is the one value `config/sentry.php` and
| `ops:preflight` both read: it is null when ERROR_TRACKING_DSN is unset or blank, and null in the local and testing
| environments (ErrorTrackingDsn::SILENT_ENVIRONMENTS), so a developer's or a test run's exception can never leave the
| machine even if a DSN is left in a local `.env`. The DSN is an integration credential: it lives in the server's
| environment only, is never logged or printed, and `ops:preflight` reports its presence, not its value.
|
| env() is read here only, so `php artisan config:cache` keeps working (see config/seeding.php).
|
*/

return [

    'dsn' => ErrorTrackingDsn::resolve(env('ERROR_TRACKING_DSN'), env('APP_ENV', 'production')),

];
