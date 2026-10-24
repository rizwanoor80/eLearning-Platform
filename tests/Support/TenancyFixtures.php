<?php

namespace Tests\Support;

use App\Enums\TenantStatus;
use App\Tenancy\DatabaseProvisioner;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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

    public static function configure(string $base): void
    {
        config()->set('database.connections.central.database', $base.'_central');
        config()->set('tenancy.enabled', true);
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

    public static function tenant(string $slug): Tenant
    {
        return Tenant::query()->where('slug', $slug)->firstOrFail();
    }
}
