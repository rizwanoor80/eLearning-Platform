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

    /** Commands allowed on another connection while no tenant is set (the registry): all but install need --path. */
    private const CENTRAL_COMMANDS = ['migrate', 'migrate:status', 'migrate:rollback', 'migrate:install'];

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

        if (config('tenancy.enabled')) {
            $this->enable();
        }
    }

    /** Everything that makes the application tenant-safe from the first query; refuses to boot on unsafe settings. */
    public function enable(): void
    {
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

            // Registry migrations are the one use: another connection, with a --path given (its value is not checked: the
            // operator names database/migrations/central, see the runbook). Fresh, refresh, reset, wipe and seed never run without a tenant.
            $database = $event->input->hasParameterOption('--database') ? $event->input->getParameterOption('--database') : null;

            if ($database !== null && $database !== false && $database !== config('tenancy.tenant_connection')
                && in_array($event->command, self::CENTRAL_COMMANDS, true)
                && ($event->command === 'migrate:install' || $event->input->hasParameterOption('--path'))) {
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

        $cache = config('cache.stores.'.config('cache.default').'.driver');

        if (in_array($cache, ['file', 'database'], true)) {
            throw TenancyException::misconfigured("the {$cache} cache driver is not tenant-safe (shared files, or a store that needs a tenant before one is set); use redis.");
        }

        $sessions = config('session.driver');

        if (in_array($sessions, ['file', 'cookie'], true)) {
            throw TenancyException::misconfigured("the {$sessions} session driver would honour one tenant's session on another host; use database or redis.");
        }

        if ($sessions === 'database' && config('session.connection') !== null && config('session.connection') !== $tenant) {
            throw TenancyException::misconfigured('the session table is per tenant; session.connection must be unset.');
        }

        if (config('queue.default') === 'database') {
            throw TenancyException::misconfigured('the database queue driver is per-tenant; use redis.');
        }
    }
}
