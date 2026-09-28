<?php

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

/**
 * R149(d) / R130. `Illuminate\Http\Middleware\TrustProxies` is unconditionally in the framework's
 * global middleware stack (Illuminate\Foundation\Configuration\Middleware::getGlobalMiddleware(),
 * confirmed by reading it directly — this app's bootstrap/app.php never calls ->trustProxies()),
 * so the only lever this app has is the `trustedproxy.proxies` config key the middleware itself
 * falls back to (TrustProxies::setTrustedProxyIpAddresses()) — see config/trustedproxy.php.
 *
 * The design consult for this cycle (CYCLE-LOG, 9a design consult) flagged that a test only
 * proving "X-Forwarded-For is ignored when TRUSTED_PROXIES is unset" is vacuous — that is already
 * true today, with no wiring at all, because the middleware trusts nothing by default. Both cases
 * are proven here: the negative one for completeness, and the positive one (set TRUSTED_PROXIES to
 * the connecting address and assert the header is then honoured) as the test that actually proves
 * the config wires through to the middleware's real behaviour.
 *
 * A request is run directly through the real middleware instance rather than a full HTTP round
 * trip, because nothing in this app echoes `$request->ip()` back to a client — there is no route
 * whose response would let an HTTP test observe it. `TrustProxies` is a plain, stateless-per-call
 * class (it reads config and the request on every `handle()`; the only mutable state is the static
 * `::at()`/`::withHeaders()` overrides this app never calls), so resolving it from the container
 * and invoking `handle()` exercises exactly the same code path a real request goes through.
 */
function trustedProxyTestRequest(string $remoteAddr, string $forwardedFor): Request
{
    return Request::create('http://project-elearning.test/up', 'GET', server: [
        'REMOTE_ADDR' => $remoteAddr,
        'HTTP_X_FORWARDED_FOR' => $forwardedFor,
    ]);
}

afterEach(function () {
    // Belt-and-suspenders: this app never calls TrustProxies::at()/::withHeaders(), so these
    // statics are never actually set by anything under test, but flushing keeps this file safe
    // regardless of test order or of another suite someday calling them.
    TrustProxies::flushState();
});

it('ignores X-Forwarded-For when TRUSTED_PROXIES is unset (R149(d), R130)', function () {
    config(['trustedproxy.proxies' => null]);
    $request = trustedProxyTestRequest(remoteAddr: '203.0.113.9', forwardedFor: '198.51.100.1');

    app(TrustProxies::class)->handle($request, fn (Request $r) => $r);

    expect($request->ip())->toBe('203.0.113.9');
});

it('honours X-Forwarded-For when TRUSTED_PROXIES names the connecting address (R149(d), R130)', function () {
    // The literal "REMOTE_ADDR" token (documented in .env.example and config/trustedproxy.php) is
    // substituted for the connection's own address by
    // TrustProxies::setTrustedProxyIpAddressesToSpecificIps() — the shape a single load balancer
    // or reverse proxy directly in front of the app needs, without hard-coding its IP.
    config(['trustedproxy.proxies' => 'REMOTE_ADDR']);
    $request = trustedProxyTestRequest(remoteAddr: '203.0.113.9', forwardedFor: '198.51.100.1');

    app(TrustProxies::class)->handle($request, fn (Request $r) => $r);

    expect($request->ip())->toBe('198.51.100.1');
});

it('trusts an explicit proxy IP list the same way, comma-separated (R149(d), R130)', function () {
    config(['trustedproxy.proxies' => '203.0.113.9, 203.0.113.10']);
    $request = trustedProxyTestRequest(remoteAddr: '203.0.113.9', forwardedFor: '198.51.100.1');

    app(TrustProxies::class)->handle($request, fn (Request $r) => $r);

    expect($request->ip())->toBe('198.51.100.1');
});

it('does not honour X-Forwarded-For from a connecting address not on the trusted list (R149(d), R130)', function () {
    config(['trustedproxy.proxies' => '203.0.113.10']); // a different address than the one connecting
    $request = trustedProxyTestRequest(remoteAddr: '203.0.113.9', forwardedFor: '198.51.100.1');

    app(TrustProxies::class)->handle($request, fn (Request $r) => $r);

    expect($request->ip())->toBe('203.0.113.9');
});
