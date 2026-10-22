<?php

use App\Enums\PaymentGatewayMode;
use App\Enums\PreflightStatus;
use App\Models\PaymentGatewayAccount;
use App\Models\User;
use App\Models\VideoProvider;
use App\Services\Ops\OpsStatusReport;
use App\Services\Ops\Preflight;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * R275: `ops:preflight` — the read-only go-live gate. Each check has a PASS and a FAIL test, nothing it prints is a
 * value, and a check that cannot be read fails.
 */
beforeEach(function () {
    $this->publicPath = sys_get_temp_dir().'/preflight-'.Str::random(12);
    File::ensureDirectoryExists($this->publicPath.'/target');
    symlink($this->publicPath.'/target', $this->publicPath.'/storage');
    app()->usePublicPath($this->publicPath);

    $this->app['env'] = 'production';
    config([
        'app.debug' => false,
        'app.url' => 'https://app.example-production.invalid',
        'app.key' => 'base64:'.base64_encode(Str::random(32)),
        'session.secure' => true,
        'session.http_only' => true,
        'mail.default' => 'smtp',
        'queue.default' => 'redis',
        'trustedproxy.proxies' => 'REMOTE_ADDR',
        'error-tracking.dsn' => 'https://public@errors.example-production.invalid/1',
    ]);
    Cache::forever(OpsStatusReport::SCHEDULER_TICK_KEY, now()->getTimestamp());

    PaymentGatewayAccount::factory()->active()->create();
    // The migration seeds one row per code, and `fake` starts active outside production.
    VideoProvider::query()->update(['is_active' => false]);
    VideoProvider::query()->where('code', 'daily')->firstOrFail()
        ->forceFill(['credentials' => ['api_key' => 'test-daily-api-key', 'webhook_secret' => 'test-daily-webhook-secret'], 'is_active' => true])->save();
});

afterEach(fn () => File::deleteDirectory($this->publicPath));

/**
 * Exit code and the command's own output. `Artisan::output()` is the last command run, which is the nested
 * `ledger:verify`, so the command gets a buffer of its own.
 *
 * @return array{0: int, 1: string}
 */
function runPreflight(): array
{
    $output = new BufferedOutput;

    return [Artisan::call('ops:preflight', [], $output), $output->fetch()];
}

/** @return array<string, PreflightStatus> */
function preflightStatuses(): array
{
    $statuses = [];

    foreach (app(Preflight::class)->run() as $result) {
        $statuses[$result->name] = $result->status;
    }

    return $statuses;
}

function expectOnlyFailing(string $name): void
{
    $failing = array_keys(array_filter(preflightStatuses(), fn (PreflightStatus $status) => ! $status->passed()));

    expect($failing)->toBe([$name]);
}

it('passes every check on a production-shaped environment and exits 0', function () {
    [$exit, $output] = runPreflight();

    expect(preflightStatuses())->each->toBe(PreflightStatus::Pass)
        ->and($exit)->toBe(0)
        ->and($output)->toContain('PASS  APP_ENV is production')->toContain('Preflight passed.');
});

it('fails APP_ENV when it is not production', function () {
    $this->app['env'] = 'rehearsal';
    config(['horizon.environments.rehearsal' => config('horizon.environments.production')]);

    expectOnlyFailing('APP_ENV is production');
});

it('fails when debug is on', function () {
    config(['app.debug' => true]);

    expectOnlyFailing('APP_DEBUG is off');
});

it('fails when the URL is not https', function (?string $url) {
    config(['app.url' => $url]);

    expectOnlyFailing('APP_URL is https');
})->with(['http://trustutor.example.invalid', '', null]);

it('fails without an application key', function () {
    config(['app.key' => '']);

    expectOnlyFailing('APP_KEY is set');
});

it('fails when session cookies are not secure', function (array $session) {
    config($session);

    expectOnlyFailing('Session cookies are secure');
})->with([
    'secure flag unset' => [['session.secure' => null]],
    'secure flag off' => [['session.secure' => false]],
    'readable by scripts' => [['session.http_only' => false]],
]);

it('fails when the mailer only logs', function (string $mailer) {
    config(['mail.default' => $mailer]);

    expectOnlyFailing('Mailer is a real transport');
})->with(['log', 'array']);

it('fails when the queue is not Redis', function () {
    config(['queue.default' => 'database']);

    expectOnlyFailing('Queue is Redis with Horizon configured');
});

it('fails when Horizon has no supervisors for this environment or one is off Redis', function (array $config) {
    config($config);

    expectOnlyFailing('Queue is Redis with Horizon configured');
})->with([
    'no entry for the environment' => [['horizon.environments' => ['local' => ['supervisor-1' => []]]]],
    'a supervisor on another connection' => [['horizon.environments.production.supervisor-1.connection' => 'database']],
]);

it('fails when the scheduler has not ticked recently or at all', function (?int $age) {
    $age === null
        ? Cache::forget(OpsStatusReport::SCHEDULER_TICK_KEY)
        : Cache::forever(OpsStatusReport::SCHEDULER_TICK_KEY, now()->getTimestamp() - $age);

    expectOnlyFailing('Scheduler is ticking');
})->with([null, OpsStatusReport::SCHEDULER_STALE_AFTER_SECONDS + 1]);

it('fails when the fake payment gateway is active', function () {
    // The model refuses this outside local/testing/rehearsal, as it should; a row written below the model is the case to catch.
    PaymentGatewayAccount::query()->update(['is_active' => false]);
    PaymentGatewayAccount::query()->where('code', 'fake')->update(['is_active' => true]);
    PaymentGatewayAccount::query()->where('code', 'stripe')->update(['is_active' => false]);

    expect(preflightStatuses())->toMatchArray([
        'Payment gateway is not the fake' => PreflightStatus::Fail,
        'A payment gateway is active' => PreflightStatus::Fail,
    ]);
});

it('fails when no payment gateway is active', function () {
    PaymentGatewayAccount::query()->update(['is_active' => false]);

    expect(preflightStatuses()['A payment gateway is active'])->toBe(PreflightStatus::Fail);
});

it('fails when the active gateway is missing credentials', function () {
    DB::table('payment_gateways')->where('is_active', true)->update(['credentials' => null]);

    expect(preflightStatuses()['A payment gateway is active'])->toBe(PreflightStatus::Fail);
});

it('reports the active gateway mode as test or live, and nothing else', function () {
    $line = collect(app(Preflight::class)->run())->first(fn ($result) => $result->name === 'A payment gateway is active');

    expect($line->note)->toBe('mode: test');

    config(['payments.live_mode_allowed' => true]);
    PaymentGatewayAccount::query()->where('is_active', true)->firstOrFail()
        ->forceFill(['mode' => PaymentGatewayMode::Live, 'credentials' => ['publishable_key' => 'pk_live_x', 'secret_key' => 'sk_live_x', 'webhook_secret' => 'whsec_x']])->save();

    $line = collect(app(Preflight::class)->run())->first(fn ($result) => $result->name === 'A payment gateway is active');

    expect($line->note)->toBe('mode: live')->and($line->passed())->toBeTrue();
});

it('fails when the video provider is the fake or there is none', function (string $case) {
    if ($case === 'none') {
        VideoProvider::query()->update(['is_active' => false]);
    } else {
        VideoProvider::query()->update(['is_active' => false]);
        VideoProvider::query()->where('code', 'fake')->update(['is_active' => true]);
    }

    expect(preflightStatuses()['Video provider is real and active'])->toBe(PreflightStatus::Fail);
})->with(['none', 'fake']);

it('fails when a webhook secret is missing on the gateway or the video provider', function (string $table) {
    $credentials = $table === 'payment_gateways'
        ? ['publishable_key' => 'pk_test_example', 'secret_key' => 'sk_test_example']
        : ['api_key' => 'test-daily-api-key'];

    // Written below the model: its guard refuses to save an active gateway that lacks credentials.
    DB::table($table)->where('is_active', true)->update(['credentials' => Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR))]);

    expect(preflightStatuses()['Webhook secrets are present'])->toBe(PreflightStatus::Fail);
})->with(['payment_gateways', 'video_providers']);

it('judges trusted proxies by shape', function (?string $proxies, bool $passes) {
    config(['trustedproxy.proxies' => $proxies]);

    expect(preflightStatuses()['Trusted proxies are set'])->toBe($passes ? PreflightStatus::Pass : PreflightStatus::Fail);
})->with([
    'REMOTE_ADDR' => ['REMOTE_ADDR', true],
    'one address' => ['10.0.0.5', true],
    'addresses and a range' => ['10.0.0.5, 172.16.0.0/12, 2001:db8::/32', true],
    'unset' => [null, false],
    'empty' => ['', false],
    'trust everyone' => ['*', false],
    'trust everyone, double' => ['**', false],
    'a typo' => ['10.0.0.5, load-balancer', false],
    'a range too wide' => ['10.0.0.0/33', false],
    'an empty entry' => ['10.0.0.5,', false],
]);

it('fails when a demo or factory account exists, trashed ones included, and ignores erased tombstones', function (string $email) {
    User::factory()->create(['email' => $email]);

    expect(preflightStatuses()['No demo accounts'])->toBe(PreflightStatus::Fail);
})->with([
    'demo.parent@example.test',
    'Someone@Example.com',
    'x@example.org',
    'x@example.net',
    'someone@staging.test',
    'someone@box.localhost',
]);

it('counts a soft-deleted demo account', function () {
    User::factory()->create(['email' => 'gone@example.test'])->delete();

    expect(preflightStatuses()['No demo accounts'])->toBe(PreflightStatus::Fail);
});

it('does not treat an erased account tombstone or a real address as a demo account', function () {
    User::factory()->create(['email' => 'deleted+7@tombstone.invalid']);
    User::factory()->create(['email' => 'someone@gmail.com']);

    expect(preflightStatuses()['No demo accounts'])->toBe(PreflightStatus::Pass);
});

it('fails without a storage link or with a dangling one', function (bool $dangling) {
    File::delete($this->publicPath.'/storage');

    if ($dangling) {
        symlink($this->publicPath.'/gone', $this->publicPath.'/storage');
    }

    expectOnlyFailing('Storage link exists');
})->with([false, true]);

it('fails when the ledger does not verify, without printing what is wrong', function () {
    Artisan::shouldReceive('call')->with('ledger:verify')->once()->andReturn(1);
    Artisan::shouldReceive('output')->never();

    expectOnlyFailing('Ledger verifies');
});

it('fails without an error tracking DSN', function (mixed $dsn) {
    config(['error-tracking.dsn' => $dsn]);

    expectOnlyFailing('Error tracking DSN is set');
})->with([null, '', '   ']);

it('counts a check that cannot be read as a failure, prints it apart and logs only the exception class', function () {
    Schema::drop('payment_gateways');
    Log::spy();

    $statuses = preflightStatuses();

    expect($statuses['Payment gateway is not the fake'])->toBe(PreflightStatus::Unreadable)
        ->and($statuses['A payment gateway is active'])->toBe(PreflightStatus::Unreadable)
        ->and($statuses['Webhook secrets are present'])->toBe(PreflightStatus::Unreadable)
        // The rest still answered.
        ->and($statuses['APP_ENV is production'])->toBe(PreflightStatus::Pass);

    Log::shouldHaveReceived('warning')->with('ops preflight check could not be evaluated', Mockery::on(
        fn (array $context) => array_keys($context) === ['check', 'exception'] && is_string($context['exception']) && ! str_contains($context['exception'], ' '),
    ));

    [$exit, $output] = runPreflight();

    expect($exit)->toBe(1)->and($output)->toContain('FAIL (unreadable)  A payment gateway is active');
});

it('exits 1 and names the failing check with a fix when one fails', function () {
    config(['app.debug' => true]);

    [$exit, $output] = runPreflight();

    expect($exit)->toBe(1)->and($output)->toContain('FAIL  APP_DEBUG is off — set APP_DEBUG=false')->toContain('Preflight failed: 1 check(s).');
});

it('never prints a configured value', function () {
    $sentinels = [
        'key' => 'base64:SENTINELKEYsentinelkey0123456789abcdef0123456=',
        'dsn' => 'https://sentineldsnpublic@sentinel-dsn-host.invalid/99',
        'url' => 'https://sentinel-url-host.invalid',
        'proxy' => '10.99.88.77',
        'webhook' => 'whsec_SENTINELWEBHOOK',
        'mail' => 'sentinel-mailer',
    ];

    config([
        'app.key' => $sentinels['key'],
        'error-tracking.dsn' => $sentinels['dsn'],
        'app.url' => $sentinels['url'],
        'trustedproxy.proxies' => $sentinels['proxy'],
        'mail.default' => $sentinels['mail'],
    ]);
    PaymentGatewayAccount::query()->active()->firstOrFail()->forceFill([
        'credentials' => ['publishable_key' => 'pk_test_example', 'secret_key' => 'sk_test_example', 'webhook_secret' => $sentinels['webhook']],
    ])->save();

    [, $output] = runPreflight();

    foreach ($sentinels as $sentinel) {
        expect($output)->not->toContain($sentinel);
    }

    expect($output)->not->toContain('pk_test_example')->not->toContain('sk_test_example');
});

it('writes nothing: no cache key, no row, no link', function () {
    $rows = fn () => [User::query()->count(), PaymentGatewayAccount::query()->count(), VideoProvider::query()->count()];
    $before = $rows();

    runPreflight();

    expect($rows())->toBe($before)
        ->and(Cache::has(OpsStatusReport::LEDGER_CHECK_KEY))->toBeFalse();
});
