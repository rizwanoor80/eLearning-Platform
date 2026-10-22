<?php

use App\Services\ErrorTracking\ErrorTrackingDsn;
use App\Services\ErrorTracking\EventScrubber;

/*
|--------------------------------------------------------------------------
| Sentry (R276)
|--------------------------------------------------------------------------
|
| Deliberately narrower than the package's own config/sentry.php, which this file overrides key by key. Everything the
| app does not need is switched off: no tracing or profiling, no log or metric forwarding, no request bodies, no PII.
| The DSN comes only from `ErrorTrackingDsn`, the same gate config/error-tracking.php uses, so there is one rule for "may
| this environment send" and no second, SENTRY_*-named way to switch it on: the SDK's own `SENTRY_DSN` and
| `SENTRY_SPOTLIGHT` defaults are overridden below. Every event passes `EventScrubber` first.
|
*/

return [

    'dsn' => ErrorTrackingDsn::resolve(env('ERROR_TRACKING_DSN'), env('APP_ENV', 'production')),

    // The SDK would otherwise read SENTRY_SPOTLIGHT and send events to that host with no DSN, from any environment.
    'spotlight' => false,

    'environment' => env('APP_ENV', 'production'),

    'release' => env('ERROR_TRACKING_RELEASE'),

    'send_default_pii' => false,

    'max_request_body_size' => 'none',

    'sample_rate' => 1.0,

    'traces_sample_rate' => null,

    'profiles_sample_rate' => null,

    'enable_logs' => false,

    'enable_metrics' => false,

    'ignore_transactions' => ['/up'],

    // Static callables, not closures, so `php artisan config:cache` can serialise them.
    'before_send' => [EventScrubber::class, 'beforeSend'],

    'before_send_transaction' => [EventScrubber::class, 'dropTransaction'],

    'before_breadcrumb' => [EventScrubber::class, 'beforeBreadcrumb'],

    'breadcrumbs' => [
        // Off: Laravel logs every reported exception with its message (bound SQL values, mail addresses) as a breadcrumb.
        'logs' => false,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => false,
    ],

    'tracing' => [
        'queue_job_transactions' => false,
        'queue_jobs' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'sql_origin' => false,
        'views' => false,
        'livewire' => false,
        'http_client_requests' => false,
        'cache' => false,
        'redis_commands' => false,
        'notifications' => false,
        'missing_routes' => false,
        'default_integrations' => false,
    ],

];
