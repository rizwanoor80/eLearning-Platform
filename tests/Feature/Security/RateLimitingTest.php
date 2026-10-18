<?php

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Rate limiting (CP8 18e, R226)
|--------------------------------------------------------------------------
| One 429 test per limiter this cycle added, the Fortify dispatcher's edges, and a walk of the live
| route list that fails on a write route with no throttle. Counters live in the array cache and reset
| with each test (phpunit.xml), so a test's own requests are the only ones counted.
*/

/**
 * Routes this app does not own, each with the reason it carries no throttle of its own. Nothing else belongs here:
 * the webhook routes and `ops/status` have named limiters (video-webhooks, ops-status) and need no entry.
 *
 * @return array<string, string> URI pattern => reason
 */
function unthrottledWriteAllowList(): array
{
    return [
        'horizon/*' => 'Horizon dashboard API: behind the Horizon gate, a vendor route group configured in config/horizon.php.',
        'livewire*/update' => 'Filament admin: Livewire polls it by design, so a per-minute count would break the panel; the guest admin login that posts through it has Filament\'s own rate limit (Login page, 5 a minute).',
        'admin/logout' => 'Filament admin sign-out: a panel route, behind the admin gate.',
    ];
}

function isThrottled(Route $route): bool
{
    foreach ($route->gatherMiddleware() as $middleware) {
        if (is_string($middleware) && (str_starts_with($middleware, 'throttle:') || str_starts_with($middleware, ThrottleRequests::class))) {
            return true;
        }
    }

    return false;
}

/** @return list<Route> */
function writeRoutes(): array
{
    return collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => array_diff($route->methods(), ['GET', 'HEAD']) !== [])
        ->values()
        ->all();
}

function not429(TestResponse $response): void
{
    expect($response->getStatusCode())->not->toBe(429);
}

function post429(int $times, string $uri, array $data = [], array $session = []): void
{
    for ($i = 0; $i < $times; $i++) {
        not429(test()->withSession($session)->post($uri, $data));
    }
}

// ---- the route walk ----------------------------------------------------------------------------

it('throttles every write route except the listed third-party ones', function () {
    $allowList = unthrottledWriteAllowList();

    $unthrottled = collect(writeRoutes())
        ->reject(fn (Route $route) => isThrottled($route))
        ->reject(fn (Route $route) => collect(array_keys($allowList))->contains(fn (string $pattern) => str($route->uri())->is($pattern)))
        ->map(fn (Route $route) => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri())
        ->values()
        ->all();

    expect($unthrottled)->toBe([]);
});

it('keeps every allow-list entry pointing at a real write route', function () {
    $routes = writeRoutes();

    foreach (unthrottledWriteAllowList() as $pattern => $reason) {
        $matches = collect($routes)->filter(fn (Route $route) => str($route->uri())->is($pattern));

        expect($matches->isNotEmpty())->toBeTrue("allow-list entry [$pattern] matches no write route: $reason");
        expect($matches->filter(fn (Route $route) => isThrottled($route)))->toHaveCount(0, "allow-list entry [$pattern] matches a route that is throttled; remove it");
    }
});

it('gives every write route Fortify registers a real limit, not Limit::none()', function () {
    $dispatcher = RateLimiter::limiter('fortify');
    $own = ['login.store', 'two-factor.login.store', 'verification.send', 'passkey.login', 'passkey.confirm', 'passkey.store'];

    $fortifyWrites = collect(writeRoutes())->filter(fn (Route $route) => in_array('throttle:fortify', $route->gatherMiddleware(), true));
    expect($fortifyWrites->count())->toBeGreaterThan(8);

    foreach ($fortifyWrites as $route) {
        $request = Request::create('/'.$route->uri(), 'POST');
        $request->setRouteResolver(fn () => $route);

        $limit = $dispatcher($request);

        if (in_array($route->getName(), $own, true)) {
            expect($limit)->toBeInstanceOf(Unlimited::class);

            continue;
        }

        // Unlimited is itself a Limit, so the class check alone would let a Limit::none() through.
        expect($limit)->toBeInstanceOf(Limit::class, 'no limit for '.$route->getName())
            ->not->toBeInstanceOf(Unlimited::class, 'no limit for '.$route->getName());
    }
});

// ---- the limiters this cycle added -------------------------------------------------------------

it('limits a password-reset request to 5 a minute for one e-mail and address', function () {
    post429(5, '/forgot-password', ['email' => 'Someone@Example.test']);

    // The key is lower-cased, so the same address in another case is the same bucket.
    test()->post('/forgot-password', ['email' => 'someone@example.test'])->assertStatus(429);
    // Another e-mail from the same address has its own bucket.
    not429(test()->post('/forgot-password', ['email' => 'other@example.test']));
});

it('limits using a reset link on its own counter', function () {
    post429(5, '/reset-password', ['email' => 'someone@example.test']);

    test()->post('/reset-password', ['email' => 'someone@example.test'])->assertStatus(429);
    // Requesting a link has a counter of its own, so the burst above did not use it (and the reverse holds below).
    not429(test()->post('/forgot-password', ['email' => 'someone@example.test']));
});

it('does not let reset-link requests use up the form that sets the new password', function () {
    post429(5, '/forgot-password', ['email' => 'someone@example.test']);

    not429(test()->post('/reset-password', ['email' => 'someone@example.test']));
});

it('sends the settings index on to the profile page', function () {
    $this->actingAs(User::factory()->create())->get('/settings')->assertRedirect('/settings/profile');
});

it('limits sign-ups to 5 a minute per address, on the parent form', function () {
    post429(5, '/register', []);

    test()->post('/register', [])->assertStatus(429);
    // A reset request has a counter of its own, so the burst above did not use it.
    not429(test()->post('/forgot-password', ['email' => 'someone@example.test']));
});

it('limits sign-ups to 5 a minute per address, on the tutor form', function () {
    post429(5, '/tutor/register', []);

    test()->post('/tutor/register', [])->assertStatus(429);
});

it('keeps one address from using up another addresses sign-ups', function () {
    post429(5, '/register', []);

    not429(test()->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->post('/register', []));
});

it('limits the two-factor challenge to 5 a minute for one pending login', function () {
    post429(5, '/two-factor-challenge', ['code' => '000000'], ['login.id' => 41]);

    test()->withSession(['login.id' => 41])->post('/two-factor-challenge', ['code' => '000000'])->assertStatus(429);
    not429(test()->withSession(['login.id' => 42])->post('/two-factor-challenge', ['code' => '000000']));
});

it('keys a session-less two-factor post by address, not by one shared key', function () {
    post429(5, '/two-factor-challenge', ['code' => '000000']);

    test()->post('/two-factor-challenge', ['code' => '000000'])->assertStatus(429);
    not429(test()->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->post('/two-factor-challenge', ['code' => '000000']));
});

it('does not fail a sign-in that sends the e-mail as an array', function () {
    expect(test()->post('/login', ['email' => ['a@example.test'], 'password' => 'x'])->getStatusCode())->toBeLessThan(500);
    expect(test()->post('/forgot-password', ['email' => ['a@example.test']])->getStatusCode())->toBeLessThan(500);
});

it('limits bookings, match requests and weekly slots to 10 a minute per user, on one counter', function () {
    $parent = User::factory()->create();
    test()->actingAs($parent);

    post429(5, route('lessons.store'));
    post429(5, route('weekly-slots.store'));

    test()->post(route('match-requests.store'))->assertStatus(429);

    // Another user has a bucket of their own.
    not429(test()->actingAs(User::factory()->create())->post(route('lessons.store')));
});

it('limits onboarding uploads to 10 a minute per user, on one counter', function () {
    $tutor = User::factory()->tutor()->create();
    test()->actingAs($tutor);

    post429(5, route('tutor.onboarding.permit'));
    post429(5, route('tutor.onboarding.documents'));

    test()->post(route('tutor.onboarding.documents'))->assertStatus(429);
    // The other onboarding steps are not uploads and are not on that counter.
    not429(test()->post(route('tutor.onboarding.linkedin')));
});

it('limits every other account write to 60 a minute per user, and never counts a page view', function () {
    $parent = User::factory()->create();
    test()->actingAs($parent);

    for ($i = 0; $i < 70; $i++) {
        test()->get(route('notifications.index'))->assertOk();
    }

    post429(60, route('notifications.read-all'));

    test()->post(route('notifications.read-all'))->assertStatus(429);
    not429(test()->actingAs(User::factory()->create())->post(route('notifications.read-all')));
});

it('puts the account baseline on the broadcasting auth route, one counter per user (R233)', function () {
    test()->actingAs(User::factory()->create());

    // The channel name does not matter here: the throttle runs before the broadcaster answers, whatever it answers.
    for ($i = 0; $i < 60; $i++) {
        not429(test()->post('/broadcasting/auth', ['channel_name' => 'private-nothing', 'socket_id' => '1.1']));
    }

    test()->post('/broadcasting/auth', ['channel_name' => 'private-nothing', 'socket_id' => '1.1'])->assertStatus(429);
    not429(test()->actingAs(User::factory()->create())->post('/broadcasting/auth', ['channel_name' => 'private-nothing', 'socket_id' => '1.1']));
});

it('puts the account baseline on the Fortify writes that have no limiter of their own', function () {
    test()->actingAs(User::factory()->create());

    post429(60, '/user/confirm-password', ['password' => 'wrong']);

    test()->post('/user/confirm-password', ['password' => 'wrong'])->assertStatus(429);
});

it('does not count Fortify page views', function () {
    for ($i = 0; $i < 70; $i++) {
        test()->get('/login')->assertOk();
    }
});
