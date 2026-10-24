<?php

use App\Models\AuditLog;
use App\Models\OpsStatusToken;
use App\Models\User;
use App\Models\VideoProvider;
use App\Models\VideoWebhookDelivery;
use App\Models\VideoWebhookEvent;
use App\Services\Ops\OpsStatusReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Mockery\MockInterface;

/**
 * R193: `GET /ops/status` — the token gate, the 404 sameness, the throttle, the audit row, and what the
 * document does and does not contain. Horizon's Redis repositories are replaced by fakes so no test needs a
 * running Redis.
 */

/**
 * @param  list<object>|null  $masters
 * @param  list<object>|null  $supervisors
 */
function opsFakeHorizon(?array $masters = null, ?array $supervisors = null): void
{
    $masters ??= [(object) ['name' => 'host-AbCd', 'pid' => 4321, 'status' => 'running', 'supervisors' => ['host-AbCd:supervisor-1']]];
    $supervisors ??= [(object) ['name' => 'host-AbCd:supervisor-1', 'master' => 'host-AbCd', 'pid' => 4330, 'status' => 'running', 'processes' => ['redis:default' => 3], 'options' => []]];

    test()->mock(MasterSupervisorRepository::class, fn (MockInterface $mock) => $mock->shouldReceive('all')->andReturn($masters));
    test()->mock(SupervisorRepository::class, fn (MockInterface $mock) => $mock->shouldReceive('all')->andReturn($supervisors));
}

/** Creates the active token row and returns the plaintext the caller would hold. */
function opsToken(array $attributes = []): string
{
    $plain = Str::random(64);

    OpsStatusToken::factory()->create(['token_hash' => OpsStatusToken::hashOf($plain)] + $attributes);

    return $plain;
}

function opsGet(?string $token = null, array $server = []): TestResponse
{
    $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

    // Headers and server variables stick to the test case between calls, so start each call clean.
    return test()->flushHeaders()->withServerVariables($server)->withHeaders($headers)->getJson('/ops/status');
}

beforeEach(function () {
    opsFakeHorizon();
});

it('answers 200 with the status document to a valid token', function () {
    $token = opsToken();

    opsGet($token)
        ->assertOk()
        ->assertJsonStructure([
            'schema_version', 'generated_at',
            'app' => ['environment', 'git_sha', 'release', 'php_version', 'laravel_version', 'debug', 'tenant'],
            'migrations' => ['pending', 'central_pending'],
            'horizon' => ['status', 'master_pid', 'supervisors'],
            'queues' => ['default_size', 'failed_jobs'],
            'scheduler' => ['status', 'last_tick_age_seconds'],
            'ledger' => ['result', 'checked_at', 'age_seconds', 'unbalanced_lessons', 'stranded_payments'],
            'video' => ['active_provider', 'deliveries_24h', 'events_24h'],
            'mail' => ['mailer', 'from_name', 'from_address'],
            'settings' => ['allow_immediate_booking', 'features' => ['match_requests', 'reviews', 'messaging']],
        ])
        ->assertJsonPath('schema_version', 1)
        ->assertJsonPath('app.environment', 'testing')
        ->assertJsonPath('app.php_version', PHP_VERSION)
        ->assertJsonPath('app.debug', (bool) config('app.debug'))
        ->assertJsonPath('horizon.status', 'running')
        ->assertJsonPath('horizon.master_pid', 4321)
        ->assertJsonPath('horizon.supervisors.0.name', 'supervisor-1')
        ->assertJsonPath('horizon.supervisors.0.process_count', 3)
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('holds exactly these keys and no others, in every section', function () {
    $document = opsGet(opsToken())->assertOk()->json();

    // An allow-list of names: a new key (a host name, a path, a connection detail) fails here until someone
    // decides it belongs in a document that has no secrets, rows or user data.
    expect(array_keys($document))->toBe(['schema_version', 'generated_at', 'app', 'migrations', 'horizon', 'queues', 'scheduler', 'ledger', 'video', 'mail', 'settings'])
        ->and(array_keys($document['app']))->toBe(['environment', 'git_sha', 'release', 'php_version', 'laravel_version', 'debug', 'tenant'])
        ->and(array_keys($document['migrations']))->toBe(['pending', 'central_pending'])
        ->and(array_keys($document['horizon']))->toBe(['status', 'master_pid', 'supervisors'])
        ->and(array_keys($document['horizon']['supervisors'][0]))->toBe(['name', 'status', 'process_count', 'processes'])
        ->and(array_keys($document['queues']))->toBe(['default_size', 'failed_jobs'])
        ->and(array_keys($document['scheduler']))->toBe(['status', 'last_tick_age_seconds'])
        ->and(array_keys($document['ledger']))->toBe(['result', 'checked_at', 'age_seconds', 'unbalanced_lessons', 'stranded_payments'])
        ->and(array_keys($document['video']))->toBe(['active_provider', 'deliveries_24h', 'events_24h'])
        ->and(array_keys($document['mail']))->toBe(['mailer', 'from_name', 'from_address'])
        ->and(array_keys($document['settings']))->toBe(['allow_immediate_booking', 'features'])
        ->and(array_keys($document['settings']['features']))->toBe(['match_requests', 'reviews', 'messaging']);
});

it('counts the last 24 hours of stored events on an index, because that table is never pruned', function () {
    expect(Schema::hasIndex('video_webhook_events', ['received_at']))->toBeTrue();
});

it('sets no cookie and starts no session', function () {
    $response = opsGet(opsToken())->assertOk();

    expect($response->headers->getCookies())->toBeEmpty();
});

it('answers the identical 404 to every kind of rejection', function () {
    $revoked = Str::random(64);
    OpsStatusToken::factory()->revoked()->create(['token_hash' => OpsStatusToken::hashOf($revoked)]);

    $rotatedAway = Str::random(64);
    OpsStatusToken::factory()->revoked()->create(['token_hash' => OpsStatusToken::hashOf($rotatedAway)]);

    // The one active token. It has no allow-list, so the Basic and query-string rows below can only be
    // refused because of how the token is presented, never because of the caller's address.
    $active = opsToken();

    $call = fn (array $headers = [], string $query = ''): TestResponse => test()->flushHeaders()->withServerVariables([])->withHeaders($headers)->getJson('/ops/status'.$query);

    $responses = collect([
        'no header' => opsGet(),
        'wrong token' => opsGet(Str::random(64)),
        'revoked token' => opsGet($revoked),
        'rotated-away token' => opsGet($rotatedAway),
        'empty bearer' => $call(['Authorization' => 'Bearer ']),
        'basic scheme' => $call(['Authorization' => 'Basic '.base64_encode('x:'.$active)]),
        'token in the query string' => $call([], '?token='.$active.'&access_token='.$active),
    ]);

    // The same token, now allow-listed to a range the caller is outside of.
    OpsStatusToken::query()->active()->firstOrFail()->update(['allowed_ips' => ['203.0.113.0/24']]);
    $responses->put('right token, address not on the list', opsGet($active, ['REMOTE_ADDR' => '198.51.100.7']));

    $responses->each(fn ($response, $label) => expect($response->getStatusCode())->toBe(404, $label));

    // Everything a prober can see, not just the body: the headers too, minus the two that differ per
    // request whatever the reason (the clock and the throttle's remaining count).
    $seen = fn ($response) => [
        'body' => $response->getContent(),
        'headers' => collect($response->headers->all())->except(['date', 'x-ratelimit-remaining'])->sortKeys()->all(),
    ];

    expect($responses->map($seen)->unique()->count())->toBe(1)
        ->and($responses->first()->json())->toBe(['message' => 'Not found.'])
        ->and(AuditLog::query()->where('action', 'ops_status_token.read')->count())->toBe(0);
});

it('answers 404 when no token has ever been made', function () {
    opsGet(Str::random(64))->assertNotFound();
});

it('lets a caller through when its address is on the allow-list, by address or by range', function () {
    $token = opsToken(['allowed_ips' => ['198.51.100.7', '203.0.113.0/24', '2001:db8::/32']]);

    opsGet($token, ['REMOTE_ADDR' => '198.51.100.7'])->assertOk();
    opsGet($token, ['REMOTE_ADDR' => '203.0.113.200'])->assertOk();
    opsGet($token, ['REMOTE_ADDR' => '2001:db8::1'])->assertOk();
    opsGet($token, ['REMOTE_ADDR' => '198.51.100.8'])->assertNotFound();
});

it('lets any address through when the allow-list is empty', function () {
    $token = opsToken(['allowed_ips' => []]);

    opsGet($token, ['REMOTE_ADDR' => '192.0.2.55'])->assertOk();
});

it('throttles per address before it checks the token, and a rejection counts', function () {
    $token = opsToken();

    foreach (range(1, 30) as $_) {
        opsGet(Str::random(64))->assertNotFound();
    }

    opsGet($token)->assertStatus(429);

    // Another address has its own counter.
    opsGet($token, ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();
});

it('audits a successful read with the caller address and stamps last_used_at, and never the token', function () {
    $plain = Str::random(64);
    $token = OpsStatusToken::factory()->create(['token_hash' => OpsStatusToken::hashOf($plain)]);

    opsGet($plain, ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();

    $audit = AuditLog::query()->where('action', 'ops_status_token.read')->sole();

    expect($audit->actor_user_id)->toBeNull()
        ->and($audit->subject_type)->toBe(OpsStatusToken::class)
        ->and($audit->subject_id)->toBe($token->id)
        ->and($audit->after)->toBe(['ip' => '203.0.113.9'])
        ->and($audit->toJson())->not->toContain($plain)->not->toContain($token->token_hash)
        ->and($token->refresh()->last_used_at)->not->toBeNull();
});

it('does not run the ledger check, or touch the ledger or payments, inside the request', function () {
    $token = opsToken();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    opsGet($token)->assertOk();

    $touching = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'ledger_entries') || str_contains($sql, '"payments"'));

    expect($touching)->toBeEmpty();
});

it('holds no secret, no row and no user datum', function () {
    $plain = opsToken();
    $hash = OpsStatusToken::hashOf($plain);

    $parent = User::factory()->create(['name' => 'Zebulon Quillfeather', 'email' => 'zebulon.quillfeather@example.test']);
    // The registry rows are seeded by their migration (the fake one active outside production).
    VideoProvider::query()->where('code', 'daily')->firstOrFail()->forceFill(['credentials' => ['api_key' => 'sekret-api-key-123', 'webhook_secret' => 'sekret-hook-456']])->save();
    VideoProvider::query()->where('code', 'fake')->firstOrFail()->forceFill(['credentials' => ['webhook_secret' => 'sekret-fake-789']])->save();

    // Set after the connection is open, so they change nothing the request does; they make the probe below
    // meaningful where the local database has no password.
    config([
        'database.connections.pgsql.password' => 'sekret-db-password-321',
        'database.redis.default.password' => 'sekret-redis-654',
        'mail.mailers.smtp.password' => 'sekret-smtp-987',
    ]);

    $body = opsGet($plain)->assertOk()->getContent();

    foreach (array_filter([$plain, $hash, 'sekret-api-key-123', 'sekret-hook-456', 'sekret-fake-789', 'sekret-db-password-321', 'sekret-redis-654', 'sekret-smtp-987', $parent->name, $parent->email, (string) config('app.key')]) as $secret) {
        expect($body)->not->toContain($secret);
    }
});

it('reports the scheduler pulse: ok when fresh, stale when old, unknown when it has never run', function () {
    $this->freezeTime();
    $token = opsToken();

    opsGet($token)->assertJsonPath('scheduler.status', 'unknown')->assertJsonPath('scheduler.last_tick_age_seconds', null);

    Cache::forever(OpsStatusReport::SCHEDULER_TICK_KEY, now()->getTimestamp() - 30);
    opsGet($token)->assertJsonPath('scheduler.status', 'ok')->assertJsonPath('scheduler.last_tick_age_seconds', 30);

    Cache::forever(OpsStatusReport::SCHEDULER_TICK_KEY, now()->getTimestamp() - 400);
    opsGet($token)->assertJsonPath('scheduler.status', 'stale')->assertJsonPath('scheduler.last_tick_age_seconds', 400);
});

it('reports the ledger verdict from the cache the scheduled check wrote, unknown before it has run', function () {
    $this->freezeTime();
    $token = opsToken();

    opsGet($token)->assertJsonPath('ledger.result', 'unknown')->assertJsonPath('ledger.checked_at', null);

    $this->artisan('ops:record-ledger-check')->assertExitCode(0);

    opsGet($token)
        ->assertJsonPath('ledger.result', 'ok')
        ->assertJsonPath('ledger.unbalanced_lessons', 0)
        ->assertJsonPath('ledger.stranded_payments', 0)
        ->assertJsonPath('ledger.age_seconds', 0);

    $this->travelTo(now()->addMinutes(20));
    opsGet($token)->assertJsonPath('ledger.result', 'stale');
});

it('reports how many migrations are pending', function () {
    $token = opsToken();

    opsGet($token)->assertJsonPath('migrations.pending', 0);

    DB::table('migrations')->where('migration', DB::table('migrations')->orderByDesc('id')->value('migration'))->delete();

    opsGet($token)->assertJsonPath('migrations.pending', 1);
});

it('counts the last 24 hours of webhook deliveries by status and the stored events', function () {
    $token = opsToken();

    VideoWebhookDelivery::factory()->count(2)->create(['http_status' => 200, 'received_at' => now()->subHours(3)]);
    VideoWebhookDelivery::factory()->create(['http_status' => 401, 'outcome' => 'invalid_signature', 'received_at' => now()->subHour()]);
    VideoWebhookDelivery::factory()->create(['http_status' => null, 'outcome' => 'unreadable', 'received_at' => now()->subHour()]);
    VideoWebhookDelivery::factory()->create(['http_status' => 200, 'received_at' => now()->subHours(30)]);

    VideoWebhookEvent::query()->create(['provider_code' => 'daily', 'event_id' => 'evt-1', 'type' => 'joined', 'room_name' => 'lesson-1', 'participant' => 'tutor', 'occurred_at' => now()->subHour(), 'received_at' => now()->subHour()]);
    VideoWebhookEvent::query()->create(['provider_code' => 'daily', 'event_id' => 'evt-2', 'type' => 'left', 'room_name' => 'lesson-1', 'participant' => 'tutor', 'occurred_at' => now()->subDays(3), 'received_at' => now()->subDays(3)]);

    opsGet($token)
        ->assertJsonPath('video.active_provider', 'fake')
        ->assertJsonPath('video.deliveries_24h.200', 2)
        ->assertJsonPath('video.deliveries_24h.401', 1)
        ->assertJsonPath('video.deliveries_24h.none', 1)
        ->assertJsonPath('video.events_24h', 1);
});

it('reports the mailer, the sender and the two settings the deploy check cares about', function () {
    $token = opsToken();

    opsGet($token)
        ->assertJsonPath('mail.mailer', config('mail.default'))
        ->assertJsonPath('mail.from_name', 'TrusTutor')
        ->assertJsonPath('settings.allow_immediate_booking', (bool) config('settings.defaults.allow_immediate_booking'))
        ->assertJsonPath('settings.features.messaging', (bool) config('settings.defaults.messaging'));
});

it('reports Horizon as inactive with no master, and as paused when a master is paused', function () {
    $token = opsToken();

    opsFakeHorizon([], []);
    opsGet($token)->assertJsonPath('horizon.status', 'inactive')->assertJsonPath('horizon.master_pid', null)->assertJsonPath('horizon.supervisors', []);

    opsFakeHorizon([(object) ['name' => 'host-AbCd', 'pid' => 7, 'status' => 'paused', 'supervisors' => []]], []);
    opsGet($token)->assertJsonPath('horizon.status', 'paused');
});

it('fails one section soft and keeps answering, without the exception text', function () {
    $token = opsToken();

    test()->mock(MasterSupervisorRepository::class, fn (MockInterface $mock) => $mock->shouldReceive('all')->andThrow(new RuntimeException('redis://user:hunter2@10.0.0.9:6379 refused')));

    $response = opsGet($token)->assertOk()->assertJsonPath('horizon.status', 'unknown')->assertJsonPath('app.environment', 'testing');

    expect($response->getContent())->not->toContain('hunter2')->not->toContain('10.0.0.9');
});

it('reads the deployed commit from a checkout\'s git files without a shell', function () {
    $sha = str_repeat('ab12', 10);
    $other = str_repeat('cd34', 10);
    $dir = storage_path('framework/testing/git-'.Str::random(8));
    mkdir($dir.'/refs/heads', 0777, true);

    file_put_contents($dir.'/HEAD', $sha."\n");
    expect(OpsStatusReport::gitShaFromDirectory($dir))->toBe($sha);

    file_put_contents($dir.'/HEAD', "ref: refs/heads/rehearsal\n");
    expect(OpsStatusReport::gitShaFromDirectory($dir))->toBeNull();

    file_put_contents($dir.'/refs/heads/rehearsal', strtoupper($other)."\n");
    expect(OpsStatusReport::gitShaFromDirectory($dir))->toBe($other);

    unlink($dir.'/refs/heads/rehearsal');
    file_put_contents($dir.'/packed-refs', "# pack-refs\n{$sha} refs/heads/main\n{$other} refs/heads/rehearsal\n");
    expect(OpsStatusReport::gitShaFromDirectory($dir))->toBe($other);

    file_put_contents($dir.'/HEAD', "ref: refs/../../etc/passwd\n");
    expect(OpsStatusReport::gitShaFromDirectory($dir))->toBeNull();

    file_put_contents($dir.'/HEAD', "something else\n");
    expect(OpsStatusReport::gitShaFromDirectory($dir))->toBeNull()
        ->and(OpsStatusReport::gitShaFromDirectory($dir.'/missing'))->toBeNull();

    File::deleteDirectory($dir);
});

it('prefers APP_GIT_SHA over the git files when it is set', function () {
    $token = opsToken();

    config(['app.git_sha' => 'ABCDEF1234567']);
    opsGet($token)->assertJsonPath('app.git_sha', 'abcdef1234567');

    config(['app.git_sha' => 'not a sha; rm -rf /']);
    opsGet($token)->assertJsonPath('app.git_sha', fn ($sha) => $sha === null || preg_match('/^[0-9a-f]{40}$/', $sha) === 1);
});
