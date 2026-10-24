<?php

namespace Tests\Support;

use App\Enums\TenantStatus;
use App\Tenancy\DatabaseProvisioner;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use PDO;
use Throwable;

/**
 * Databases for tenancy tests (ADR-032). Names derive from the (parallel-suffixed) test database, so every
 * `--parallel` process has its own central database and its own tenants. Built once per process; the config that points
 * at them is re-applied for every test because every test boots a fresh application.
 *
 * Tenant `a` is the tenant the whole suite runs as under TENANCY_SPIKE=1 (RefreshDatabase manages its schema); `x` and
 * `y` belong to the tenancy tests and are migrated here.
 */
final class TenancyFixtures
{
    /** @var array<string, true> */
    private static array $built = [];

    /** slug => hosts (first is primary) */
    private const TENANTS = [
        'a' => ['localhost', 'a.test'],
        'x' => ['x.test'],
        'y' => ['y.test'],
    ];

    /** @var list<string> databases to drop when the process ends */
    private static array $databases = [];

    private static bool $shutdownRegistered = false;

    /**
     * The per-process database name the fixtures hang off. Laravel switches to `<db>_test_<token>` only for tests that
     * use a database trait; a plain test would otherwise share one unsuffixed base with every other `--parallel`
     * process and race them on its central database, so the token is applied here regardless.
     */
    public static function base(): string
    {
        $base = (string) config('database.connections.pgsql.database');
        $token = ParallelTesting::token();

        if ($token !== false && ! str_ends_with($base, "_test_{$token}")) {
            $base .= "_test_{$token}";
        }

        return $base;
    }

    public static function configure(string $base): void
    {
        config()->set('database.connections.central.database', $base.'_central');
        config()->set('tenancy.enabled', true);
        // Same pins the provider applies when tenancy is enabled at boot.
        config()->set('queue.failed.database', 'central');
        config()->set('queue.batching.database', 'central');
        config()->set('database.connections.pgsql.database', config('tenancy.no_tenant_database'));
        DB::purge('pgsql');
        config()->set('tenancy.database_prefix', $base.'_t');
    }

    public static function ensure(string $base): void
    {
        self::configure($base);

        if (isset(self::$built[$base])) {
            return;
        }

        $provisioner = new DatabaseProvisioner;

        self::dropAtShutdown($base);

        // A leftover from a run that died before shutdown.
        $provisioner->exists($base.'_central') || $provisioner->create($base.'_central');
        DB::purge('central');
        Artisan::call('migrate:fresh', ['--database' => 'central', '--path' => 'database/migrations/central', '--force' => true]);

        foreach (self::TENANTS as $slug => $hosts) {
            $database = $base.'_t'.$slug;
            $provisioner->exists($database) || $provisioner->create($database);

            $tenant = Tenant::query()->create(['slug' => $slug, 'name' => strtoupper($slug), 'database' => $database, 'status' => TenantStatus::Active]);

            foreach ($hosts as $i => $host) {
                $tenant->domains()->create(['host' => $host, 'is_primary' => $i === 0, 'verified_at' => now()]);
            }

            if ($slug !== 'a') {
                $provisioner->migrate($tenant);
            }
        }

        self::$built[$base] = true;
    }

    /**
     * The fixture databases are throw-away: drop them when the process ends so a run leaves nothing behind. A raw PDO
     * built from parameters captured now, because the application (and its connections) is gone by shutdown.
     */
    private static function dropAtShutdown(string $base): void
    {
        self::$databases = [...self::$databases, $base.'_central', ...array_map(fn ($slug) => $base.'_t'.$slug, array_keys(self::TENANTS))];

        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        $c = config('database.connections.central');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $c['host'], $c['port']);
        $user = $c['username'];
        $password = $c['password'];

        register_shutdown_function(function () use ($dsn, $user, $password): void {
            try {
                $pdo = new PDO($dsn, $user, $password);

                foreach (array_unique(self::$databases) as $database) {
                    $pdo->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
                }
            } catch (Throwable) {
                // Best effort: the next run drops what is left.
            }
        });
    }

    public static function tenant(string $slug): Tenant
    {
        return Tenant::query()->where('slug', $slug)->firstOrFail();
    }
}
