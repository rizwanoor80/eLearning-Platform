<?php

use App\Services\ErrorTracking\EventScrubber;

/*
|--------------------------------------------------------------------------
| Sentry (R276)
|--------------------------------------------------------------------------
|
| Deliberately narrower than the package's own config/sentry.php, which this file overrides key by key. Everything the
| app does not need is switched off: no tracing or profiling, no log or metric forwarding, no request bodies, no PII.
| The DSN comes only from config/error-tracking.php (loaded just before this file), so there is one gate for "may this
| environment send" and no second, SENTRY_*-named way to switch it on. Every event passes `EventScrubber` first.
|
*/

return [

    'dsn' => config('error-tracking.dsn'),

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
        'logs' => true,
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
