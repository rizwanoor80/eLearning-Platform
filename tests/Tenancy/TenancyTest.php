<?php

use App\Enums\TenantStatus;
use App\Http\Middleware\IdentifyTenant;
use App\Providers\TenancyServiceProvider;
use App\Tenancy\DatabaseProvisioner;
use App\Tenancy\Exceptions\TenancyException;
use App\Tenancy\Tenant;
use App\Tenancy\TenantDomain;
use App\Tenancy\TenantManager;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\TenancyFixtures;
use Tests\Tenancy\FailsTenantJob;
use Tests\Tenancy\LeaksTransactionJob;
use Tests\Tenancy\RecordsTenantJob;

beforeEach(function () {
    TenancyFixtures::ensure(TenancyFixtures::base());

    $this->tenants = app(TenantManager::class);

    // Each of x and y gets a probe table holding one row that names its own tenant.
    foreach (['x', 'y'] as $slug) {
        $this->tenants->run(TenancyFixtures::tenant($slug), function () use ($slug) {
            Schema::dropIfExists('tenancy_probe');
            Schema::create('tenancy_probe', fn ($table) => $table->string('note'));
            DB::table('tenancy_probe')->insert(['note' => $slug.'-row']);
        });
    }

    Route::middleware('web')->get('/tenancy-probe', fn () => response()->json([
        'tenant' => app(TenantManager::class)->current()?->slug,
        'database' => DB::selectOne('select current_database() as d')->d,
        'rows' => DB::table('tenancy_probe')->pluck('note'),
    ]));
});

afterEach(function () {
    $this->tenants->end();
});

it('answers a.test-style hosts from different databases', function () {
    $x = $this->getJson('http://x.test/tenancy-probe')->assertOk()->json();
    $y = $this->getJson('http://y.test/tenancy-probe')->assertOk()->json();

    expect($x['tenant'])->toBe('x')
        ->and($x['rows'])->toBe(['x-row'])
        ->and($y['tenant'])->toBe('y')
        ->and($y['rows'])->toBe(['y-row'])
        ->and($x['database'])->not->toBe($y['database']);
});

it('answers 404 for a host no tenant owns', function () {
    $this->getJson('http://nobody.test/tenancy-probe')->assertNotFound();
});

it('answers 503 for a suspended tenant and serves it again once reinstated', function () {
    $x = TenancyFixtures::tenant('x');
    $x->update(['status' => TenantStatus::Suspended]);

    try {
        $this->getJson('http://x.test/tenancy-probe')->assertStatus(503);
        $x->update(['status' => TenantStatus::Active]);
        $this->getJson('http://x.test/tenancy-probe')->assertOk();
    } finally {
        $x->update(['status' => TenantStatus::Active]);
    }
});

it('fails closed with no tenant, and after tenancy ended', function () {
    expect(fn () => DB::table('tenancy_probe')->count())->toThrow(QueryException::class, '__no_tenant__');

    $this->tenants->initialize(TenancyFixtures::tenant('x'));
    expect(DB::table('tenancy_probe')->count())->toBe(1);

    $this->tenants->end();
    expect(fn () => DB::table('tenancy_probe')->count())->toThrow(QueryException::class, '__no_tenant__');
});

it('refuses to switch tenant inside a transaction and treats the same tenant as a no-op', function () {
    $this->tenants->initialize(TenancyFixtures::tenant('x'));

    DB::beginTransaction();

    try {
        $this->tenants->initialize(TenancyFixtures::tenant('x'));
        expect(DB::transactionLevel())->toBe(1);

        expect(fn () => $this->tenants->initialize(TenancyFixtures::tenant('y')))->toThrow(TenancyException::class);
        expect(fn () => $this->tenants->end())->toThrow(TenancyException::class);
    } finally {
        DB::rollBack();
    }

    $this->tenants->initialize(TenancyFixtures::tenant('y'));
    expect(DB::table('tenancy_probe')->pluck('note')->all())->toBe(['y-row']);
});

it('restores the previous tenant after run()', function () {
    $this->tenants->initialize(TenancyFixtures::tenant('x'));

    $inner = $this->tenants->run(TenancyFixtures::tenant('y'), fn () => DB::table('tenancy_probe')->value('note'));

    expect($inner)->toBe('y-row')
        ->and($this->tenants->current()->slug)->toBe('x')
        ->and(DB::table('tenancy_probe')->value('note'))->toBe('x-row');
});

it('bases absolute URLs on the tenant primary host', function () {
    $this->tenants->initialize(TenancyFixtures::tenant('x'));
    expect(url('/p'))->toEndWith('://x.test/p');

    $this->tenants->initialize(TenancyFixtures::tenant('y'));
    expect(url('/p'))->toEndWith('://y.test/p');
});

it('keeps cache keys per tenant on a prefixed store', function () {
    config()->set('cache.default', 'redis');
    config()->set('cache.stores.redis.connection', 'cache');
    $key = 'tenancy-test-'.uniqid();

    try {
        $this->tenants->initialize(TenancyFixtures::tenant('x'));
        Cache::put($key, 'from-x', 60);

        $this->tenants->initialize(TenancyFixtures::tenant('y'));
        expect(Cache::get($key))->toBeNull();

        $this->tenants->initialize(TenancyFixtures::tenant('x'));
        expect(Cache::get($key))->toBe('from-x');
    } finally {
        Cache::forget($key);
    }
});

it('runs a queued job against the tenant that dispatched it, in a worker that has no tenant', function () {
    $queue = 'tenancy-spike-'.uniqid();
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis.queue', $queue);

    $this->tenants->run(TenancyFixtures::tenant('y'), function () use ($queue) {
        dispatch(new RecordsTenantJob('y1'))->onQueue($queue);
        // A PendingDispatch fires when it is destroyed: it must go before the tenant is restored.
    });
    $this->tenants->run(TenancyFixtures::tenant('x'), function () use ($queue) {
        dispatch(new RecordsTenantJob('x1'))->onQueue($queue);
    });

    expect($this->tenants->current())->toBeNull();

    try {
        // --memory: late in a full run this process is past the worker's 128 MB default and would quit after one job.
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--stop-when-empty' => true, '--tries' => 1, '--memory' => 4096]);
    } finally {
        Redis::connection()->del(["queues:{$queue}", "queues:{$queue}:reserved", "queues:{$queue}:delayed", "queues:{$queue}:notify"]);
    }

    expect($this->tenants->current())->toBeNull();

    $notes = fn (string $slug) => $this->tenants->run(TenancyFixtures::tenant($slug), fn () => DB::table('tenancy_probe')->orderBy('note')->pluck('note')->all());

    expect($notes('x'))->toBe(['job:x1', 'x-row'])
        ->and($notes('y'))->toBe(['job:y1', 'y-row']);
});

it('refuses a queued payload that carries no tenant', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['displayName' => 'Legacy']);
    $job->shouldReceive('resolveName')->andReturn('Legacy');

    expect(fn () => Event::dispatch(new JobProcessing('redis', $job)))->toThrow(TenancyException::class);
});

it('runs a command once per tenant and carries on after one tenant fails', function () {
    Artisan::command('tenancy:probe {--fail-on=}', function () {
        $slug = app(TenantManager::class)->currentOrFail()->slug;

        if (! in_array($slug, ['x', 'y'], true)) {
            return 0;
        }

        if ($this->option('fail-on') === $slug) {
            throw new RuntimeException("boom in {$slug}");
        }

        DB::table('tenancy_probe')->insert(['note' => 'cmd']);

        return 0;
    });

    $this->artisan('tenants:each', ['artisan' => 'tenancy:probe --fail-on=x'])
        ->expectsOutputToContain('Failed on: x')
        ->assertExitCode(1);

    $notes = fn (string $slug) => $this->tenants->run(TenancyFixtures::tenant($slug), fn () => DB::table('tenancy_probe')->orderBy('note')->pluck('note')->all());

    expect($notes('x'))->toBe(['x-row'])
        ->and($notes('y'))->toBe(['cmd', 'y-row']);
});

/** Run a Redis worker over one throw-away queue until it is empty. */
function runWorker(string $queue): void
{
    try {
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--stop-when-empty' => true, '--tries' => 1, '--memory' => 4096]);
    } finally {
        Redis::connection()->del(["queues:{$queue}", "queues:{$queue}:reserved", "queues:{$queue}:delayed", "queues:{$queue}:notify"]);
    }
}

it('runs a failed() hook under the job tenant, records the failure centrally and restores the tenant', function () {
    $queue = 'tenancy-spike-'.uniqid();
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis.queue', $queue);
    $central = DB::connection('central');
    $central->table('failed_jobs')->delete();

    $this->tenants->run(TenancyFixtures::tenant('y'), function () use ($queue) {
        dispatch(new FailsTenantJob('f1'))->onQueue($queue);
    });

    try {
        runWorker($queue);

        expect($this->tenants->current())->toBeNull()
            ->and($central->table('failed_jobs')->count())->toBe(1)
            ->and($this->tenants->run(TenancyFixtures::tenant('y'), fn () => DB::table('tenancy_probe')->orderBy('note')->pluck('note')->all()))->toBe(['failed:f1', 'y-row'])
            ->and($this->tenants->run(TenancyFixtures::tenant('x'), fn () => DB::table('tenancy_probe')->pluck('note')->all()))->toBe(['x-row']);
    } finally {
        $central->table('failed_jobs')->delete();
    }
});

it('fails the queued job of a tenant that is not active, into the central failed_jobs', function () {
    $queue = 'tenancy-spike-'.uniqid();
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis.queue', $queue);
    $central = DB::connection('central');
    $central->table('failed_jobs')->delete();
    $x = TenancyFixtures::tenant('x');

    $this->tenants->run($x, function () use ($queue) {
        dispatch(new RecordsTenantJob('late'))->onQueue($queue);
    });
    $x->update(['status' => TenantStatus::Suspended]);

    try {
        runWorker($queue);

        expect($central->table('failed_jobs')->count())->toBe(1)
            ->and($central->table('failed_jobs')->value('exception'))->toContain('refusing to run its queued job')
            ->and($this->tenants->current())->toBeNull();
    } finally {
        $x->update(['status' => TenantStatus::Active]);
        $central->table('failed_jobs')->delete();
    }

    expect($this->tenants->run($x, fn () => DB::table('tenancy_probe')->pluck('note')->all()))->toBe(['x-row']);
});

it('refuses a payload for a suspended or provisioning tenant', function (TenantStatus $status) {
    $x = TenancyFixtures::tenant('x');
    $x->update(['status' => $status]);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['tenant_id' => $x->id]);
    $job->shouldReceive('resolveName')->andReturn('Late');

    try {
        expect(fn () => Event::dispatch(new JobProcessing('redis', $job)))->toThrow(TenancyException::class, 'refusing to run its queued job')
            ->and($this->tenants->current())->toBeNull();
    } finally {
        $x->update(['status' => TenantStatus::Active]);
    }
})->with([TenantStatus::Suspended, TenantStatus::Provisioning]);

it('restores the outer tenant when a nested run() throws', function () {
    $this->tenants->initialize(TenancyFixtures::tenant('x'));

    try {
        $this->tenants->run(TenancyFixtures::tenant('y'), function () {
            $this->tenants->run(TenancyFixtures::tenant('x'), fn () => throw new RuntimeException('inner'));
        });
    } catch (RuntimeException) {
    }

    expect($this->tenants->current()->slug)->toBe('x')
        ->and(DB::table('tenancy_probe')->value('note'))->toBe('x-row');
});

it('keeps throttle counters per tenant, including a limiter resolved before the switch', function () {
    config()->set('cache.default', 'redis');
    config()->set('cache.stores.redis.connection', 'cache');
    $key = 'tenancy-throttle-'.uniqid();

    try {
        $this->tenants->initialize(TenancyFixtures::tenant('x'));
        RateLimiter::hit($key, 60);
        RateLimiter::hit($key, 60);

        $this->tenants->initialize(TenancyFixtures::tenant('y'));
        expect((int) RateLimiter::attempts($key))->toBe(0);

        RateLimiter::hit($key, 60);

        $this->tenants->initialize(TenancyFixtures::tenant('x'));
        expect((int) RateLimiter::attempts($key))->toBe(2);
    } finally {
        foreach (['x', 'y'] as $slug) {
            $this->tenants->initialize(TenancyFixtures::tenant($slug));
            RateLimiter::clear($key);
        }
    }
});

it('refuses to switch tenant while tenancy is disabled', function () {
    config()->set('tenancy.enabled', false);

    expect(fn () => $this->tenants->initialize(TenancyFixtures::tenant('x')))->toThrow(TenancyException::class, 'disabled');
});

it('refuses schema commands on the tenant connection with no tenant, but not on central or inside a tenant', function () {
    // The framework reroutes Symfony's command events to CommandStarting only outside unit tests, so the event itself is fired.
    $starting = fn (string $command, array $input = []) => Event::dispatch(new CommandStarting($command, new ArrayInput($input), new NullOutput));

    expect(fn () => $starting('migrate:status'))->toThrow(TenancyException::class, 'migrate:status')
        ->and(fn () => $starting('db:seed'))->toThrow(TenancyException::class, 'db:seed')
        ->and(fn () => $starting('migrate', ['--database' => 'pgsql']))->toThrow(TenancyException::class)
        ->and(fn () => $starting('migrate', ['--database' => 'central']))->toThrow(TenancyException::class)
        ->and(fn () => $starting('migrate:fresh', ['--database' => 'central', '--path' => 'database/migrations/central']))->toThrow(TenancyException::class)
        ->and(fn () => $starting('db:seed', ['--database' => 'central']))->toThrow(TenancyException::class)
        ->and(fn () => $starting('migrate', ['--database' => 'central', '--path' => 'database/migrations/central']))->not->toThrow(TenancyException::class)
        ->and(fn () => $starting('migrate:status', ['--database' => 'central', '--path' => 'database/migrations/central']))->not->toThrow(TenancyException::class)
        ->and(fn () => $starting('queue:work'))->not->toThrow(TenancyException::class);

    $this->tenants->run(TenancyFixtures::tenant('x'), function () use ($starting) {
        expect(fn () => $starting('migrate:status'))->not->toThrow(TenancyException::class);
    });
});

it('puts every route behind IdentifyTenant, bar the static assets', function () {
    $router = app('router');
    $unguarded = [];

    foreach ($router->getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();

        if ($uri === 'up' || preg_match('#^livewire[^/]*/.+\.(js|css|map)$#', $uri) === 1) {
            continue;
        }

        if (! in_array(IdentifyTenant::class, $router->gatherRouteMiddleware($route), true)) {
            $unguarded[] = implode('|', $route->methods()).' '.$uri;
        }
    }

    expect($unguarded)->toBe([]);
});

it('creates a tenant as provisioning, activates it once migrated, and refuses a bad host or a duplicate', function () {
    $provisioner = new DatabaseProvisioner;
    $database = config('tenancy.database_prefix').'zed';

    try {
        $this->artisan('tenants:create', ['slug' => 'zed', '--host' => 'ZED.test'])->assertSuccessful();

        $zed = TenancyFixtures::tenant('zed');
        expect($zed->isActive())->toBeTrue()
            ->and($zed->domains()->value('host'))->toBe('zed.test')
            ->and($provisioner->exists($database))->toBeTrue()
            ->and($this->tenants->run($zed, fn () => Schema::hasTable('lessons')))->toBeTrue();

        $this->artisan('tenants:create', ['slug' => 'zed2', '--host' => 'zed.test'])->expectsOutputToContain('already belongs')->assertFailed();
        $this->artisan('tenants:create', ['slug' => 'zed3', '--host' => 'bad host!'])->expectsOutputToContain('not a valid host')->assertFailed();
        // Only the configured template database is accepted: not the central registry, not a tenant, not a missing one.
        $this->artisan('tenants:create', ['slug' => 'zed4', '--template' => (string) config('database.connections.central.database')])->expectsOutputToContain('TENANCY_TEMPLATE_DATABASE')->assertFailed();

        config()->set('tenancy.template_database', 'nope_no_such_db');
        $this->artisan('tenants:create', ['slug' => 'zed4', '--template' => 'nope_no_such_db'])->expectsOutputToContain('TENANCY_TEMPLATE_DATABASE')->assertFailed();

        config()->set('tenancy.template_database', $database);
        $this->artisan('tenants:create', ['slug' => 'zed5', '--template' => $database])->expectsOutputToContain('not a tenant')->assertFailed();
    } finally {
        Tenant::query()->where('slug', 'zed')->first()?->delete();
        $provisioner->drop($database);
    }
});

it('rolls a failed tenants:create back completely', function () {
    $database = config('tenancy.database_prefix').'rb';
    $mock = Mockery::mock(DatabaseProvisioner::class)->makePartial();
    $mock->shouldReceive('migrate')->andThrow(new RuntimeException('migration blew up'));
    $this->app->instance(DatabaseProvisioner::class, $mock);

    $this->artisan('tenants:create', ['slug' => 'rb', '--host' => 'rb.test'])
        ->expectsOutputToContain('rolled back')
        ->assertFailed();

    expect(Tenant::query()->where('slug', 'rb')->exists())->toBeFalse()
        ->and(TenantDomain::query()->where('host', 'rb.test')->exists())->toBeFalse()
        ->and((new DatabaseProvisioner)->exists($database))->toBeFalse();
});

it('refuses to create or drop the sentinel, the central or the server databases', function () {
    $provisioner = new DatabaseProvisioner;

    expect(fn () => $provisioner->create(config('tenancy.no_tenant_database')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $provisioner->drop('postgres'))->toThrow(InvalidArgumentException::class, 'protected')
        ->and(fn () => $provisioner->drop('template1'))->toThrow(InvalidArgumentException::class, 'protected')
        ->and(fn () => $provisioner->drop((string) config('database.connections.central.database')))->toThrow(InvalidArgumentException::class, 'protected');
});

it('fails tenants:migrate and tenants:each for a slug that matches nothing', function () {
    $this->artisan('tenants:migrate', ['--tenant' => 'nope'])->assertFailed();
    $this->artisan('tenants:each', ['artisan' => 'list', '--tenant' => 'nope'])->assertFailed();
});

it('rolls back a transaction a job left open and still runs the next job under its own tenant', function () {
    $queue = 'tenancy-spike-'.uniqid();
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis.queue', $queue);
    $central = DB::connection('central');
    $central->table('failed_jobs')->delete();

    $this->tenants->run(TenancyFixtures::tenant('x'), function () use ($queue) {
        dispatch(new LeaksTransactionJob)->onQueue($queue);
    });
    $this->tenants->run(TenancyFixtures::tenant('y'), function () use ($queue) {
        dispatch(new FailsTenantJob('after-leak'))->onQueue($queue);
    });

    try {
        runWorker($queue);

        expect($this->tenants->current())->toBeNull()
            ->and(DB::connection()->transactionLevel())->toBe(0)
            ->and($this->tenants->run(TenancyFixtures::tenant('x'), fn () => DB::table('tenancy_probe')->pluck('note')->all()))->toBe(['x-row'])
            ->and($this->tenants->run(TenancyFixtures::tenant('y'), fn () => DB::table('tenancy_probe')->orderBy('note')->pluck('note')->all()))->toBe(['failed:after-leak', 'y-row']);
    } finally {
        $central->table('failed_jobs')->delete();
    }
});

it('keeps the original exception and the previous tenant when run() leaves a transaction open', function () {
    $this->tenants->initialize(TenancyFixtures::tenant('x'));

    expect(fn () => $this->tenants->run(TenancyFixtures::tenant('y'), function () {
        DB::beginTransaction();

        throw new RuntimeException('original');
    }))->toThrow(RuntimeException::class, 'original');

    expect($this->tenants->current()->slug)->toBe('x')
        ->and(DB::transactionLevel())->toBe(0);
});

it('refuses to enable tenancy under settings that would leak', function (string $key, mixed $value, string $message) {
    $before = config()->all();

    try {
        config()->set('session.driver', 'database');
        config()->set($key, $value);

        expect(fn () => (new TenancyServiceProvider($this->app))->enable())->toThrow(TenancyException::class, $message);
    } finally {
        config()->set($before);
    }
})->with([
    'a url that overrides the database name' => ['database.connections.pgsql.url', 'pgsql://u@h/db', 'DB_URL'],
    'no central database' => ['database.connections.central.database', '', 'DB_CENTRAL_DATABASE'],
    'file cache' => ['cache.stores.'.'array.driver', 'file', 'file cache'],
    'database cache' => ['cache.stores.'.'array.driver', 'database', 'database cache'],
    'database queue' => ['queue.default', 'database', 'queue'],
    'file sessions' => ['session.driver', 'file', 'session'],
    'cookie sessions' => ['session.driver', 'cookie', 'session'],
    'sessions on central' => ['session.connection', 'central', 'session.connection'],
]);

it('pins the sentinel database and the failure tables to central when enabled', function () {
    $before = config()->all();

    try {
        config()->set('session.driver', 'redis');
        (new TenancyServiceProvider($this->app))->enable();

        expect(config('database.connections.pgsql.database'))->toBe(config('tenancy.no_tenant_database'))
            ->and(config('queue.failed.database'))->toBe(config('tenancy.central_connection'))
            ->and(config('queue.batching.database'))->toBe(config('tenancy.central_connection'));
    } finally {
        config()->set($before);
    }
});
