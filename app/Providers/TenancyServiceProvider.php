<?php

namespace App\Providers;

use App\Tenancy\Exceptions\TenancyException;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantQueue;
use App\Tenancy\TenantRateLimiter;
use Illuminate\Cache\RateLimiter;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    /** Commands that read or write the tenant connection's schema; with no tenant they would hit the sentinel database. */
    private const SCHEMA_COMMANDS = [
        'migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'migrate:install',
        'migrate:status', 'db:seed', 'db:wipe', 'schema:dump',
    ];

    public function register(): void
    {
        $this->app->singleton(TenantManager::class);
        $this->app->singleton(TenantQueue::class);

        // The framework's limiter keeps the cache repository it was built with; this one can be re-pointed on a switch.
        // CacheServiceProvider is deferred and would overwrite a plain binding made here, so the framework's own
        // instance is swapped through extend().
        $this->app->extend(RateLimiter::class, fn ($limiter, $app) => new TenantRateLimiter(
            $app->make('cache')->driver($app['config']->get('cache.limiter'))
        ));

        if (! config('tenancy.enabled')) {
            return;
        }

        $this->assertConfiguration();

        // Fail closed from the first query: until a tenant is initialised the tenant connection names a database
        // that does not exist.
        config()->set('database.connections.'.config('tenancy.tenant_connection').'.database', config('tenancy.no_tenant_database'));

        // Failed jobs and job batches are tenant-agnostic: a failure must be recordable with no tenant set, and one
        // table lets an operator see every tenant's failures (the tenant database's own copies stay empty).
        config()->set('queue.failed.database', config('tenancy.central_connection'));
        config()->set('queue.batching.database', config('tenancy.central_connection'));
    }

    public function boot(): void
    {
        $this->app->make(TenantQueue::class)->register();

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $tenants = $this->app->make(TenantManager::class);

            if (! $tenants->enabled() || $tenants->current() !== null || ! in_array($event->command, self::SCHEMA_COMMANDS, true)) {
                return;
            }

            // `--database=central` (or any connection but the tenant one) is fine.
            $database = $event->input->hasParameterOption('--database') ? $event->input->getParameterOption('--database') : null;

            if ($database !== null && $database !== false && $database !== config('tenancy.tenant_connection')) {
                return;
            }

            throw TenancyException::commandWithoutTenant($event->command);
        });
    }

    /** Settings under which tenancy cannot be made safe: refuse to boot rather than leak. */
    private function assertConfiguration(): void
    {
        $tenant = config('tenancy.tenant_connection');

        if ($tenant !== config('database.default')) {
            throw TenancyException::misconfigured("the tenant connection [{$tenant}] must be the default database connection.");
        }

        if (filled(config("database.connections.{$tenant}.url"))) {
            throw TenancyException::misconfigured('DB_URL overrides the per-tenant database name; unset it and use the DB_* parts.');
        }

        if (blank(config('database.connections.'.config('tenancy.central_connection').'.database'))) {
            throw TenancyException::misconfigured('DB_CENTRAL_DATABASE is not set.');
        }

        if (config('cache.default') === 'file' || config('cache.stores.'.config('cache.default').'.driver') === 'file') {
            throw TenancyException::misconfigured('the file cache driver is not tenant-safe; use redis.');
        }

        if (config('queue.default') === 'database') {
            throw TenancyException::misconfigured('the database queue driver is per-tenant; use redis.');
        }
    }
}
