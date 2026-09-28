<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies (R149(d) / R130)
    |--------------------------------------------------------------------------
    |
    | `Illuminate\Http\Middleware\TrustProxies` is always in the framework's global middleware
    | stack (Illuminate\Foundation\Configuration\Middleware::getGlobalMiddleware()), whether or
    | not `bootstrap/app.php` calls `->trustProxies()`. This app never calls it, so the middleware
    | falls back to this file's `proxies` key (`TrustProxies::setTrustedProxyIpAddresses()`) —
    | this is the config key the framework itself checks, not an app-invented one.
    |
    | Empty by default: no proxy is trusted, so `X-Forwarded-For`/`-Host`/`-Port`/`-Proto` are
    | ignored and `$request->ip()` is always the connecting socket's address. Set TRUSTED_PROXIES
    | to a comma-separated list of proxy IPs/CIDRs, or the literal string "REMOTE_ADDR" to trust
    | whichever address the connection actually came from — the shape a single load balancer or
    | reverse proxy in front of the app needs, without hard-coding its address.
    |
    | Read from env() only here, never in bootstrap/app.php or a service provider, so
    | `php artisan config:cache` keeps working (env() reads nothing once the config cache is
    | built) — see config/seeding.php for the same convention.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
