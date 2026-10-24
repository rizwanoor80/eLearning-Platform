<?php

use App\Enums\TenantStatus;
use App\Tenancy\Exceptions\TenancyException;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TenancyFixtures;
use Tests\Tenancy\RecordsTenantJob;

beforeEach(function () {
    TenancyFixtures::ensure((string) config('database.connections.pgsql.database'));

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
