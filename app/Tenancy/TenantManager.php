<?php

namespace App\Tenancy;

use App\Tenancy\Exceptions\TenancyException;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Holds the current tenant and repoints everything that is per-tenant when it changes (ADR-032).
 *
 * The tenant connection keeps the name `pgsql` and the same Connection object: a switch disconnects it and re-points it
 * at the tenant's database, so anything that captured the object (a database cache store, the query listeners) follows
 * the switch, and a long-lived worker holds exactly one tenant connection. With no tenant it points at a database that
 * does not exist. Services built from config before the switch (cache store, mailer, disks, URL root) are forgotten so
 * the next use rebuilds them with the new config; the RateLimiter is rebound in place.
 */
final class TenantManager
{
    private ?Tenant $current = null;

    private ?string $baseCachePrefix = null;

    public function __construct(private readonly Application $app) {}

    public function enabled(): bool
    {
        return (bool) config('tenancy.enabled');
    }

    public function current(): ?Tenant
    {
        return $this->current;
    }

    public function currentOrFail(): Tenant
    {
        return $this->current ?? throw TenancyException::noTenant();
    }

    public function initialize(Tenant $tenant): void
    {
        if (! $this->enabled()) {
            throw TenancyException::disabled();
        }

        if ($this->current?->is($tenant)) {
            return;
        }

        $this->apply($tenant);
    }

    public function end(): void
    {
        if ($this->current === null) {
            return;
        }

        $this->apply(null);
    }

    /**
     * Run a callback as a tenant and restore whatever was current before. Switching to the tenant that is already
     * current is a no-op, which is what keeps a sync-queue job from tearing down the request around it.
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->current;

        $this->initialize($tenant);

        try {
            return $callback();
        } finally {
            $this->restore($previous);
        }
    }

    /**
     * Go back to a tenant (or none) that was current before a run or a job. Whatever transaction the work left open on
     * the tenant connection is rolled back first: staying on the old tenant because a switch was refused would run the
     * next job's hooks against the wrong database, and the original exception would be hidden by the refusal.
     */
    public function restore(?Tenant $previous): void
    {
        if ($this->current?->is($previous) || ($previous === null && $this->current === null)) {
            return;
        }

        $connection = DB::connection(config('tenancy.tenant_connection'));

        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }

        $previous === null ? $this->end() : $this->initialize($previous);
    }

    /** The database a switch lands on: the tenant's, or the one that does not exist. */
    private function apply(?Tenant $tenant): void
    {
        $name = config('tenancy.tenant_connection');
        $connection = DB::connection($name);

        if ($connection->transactionLevel() > 0) {
            throw TenancyException::switchInTransaction();
        }

        // Looked up first: if the registry is unreachable nothing has been switched yet.
        $host = $tenant?->domains()->where('is_primary', true)->value('host');

        $this->current = $tenant;

        $database = $tenant->database ?? config('tenancy.no_tenant_database');
        config()->set("database.connections.{$name}.database", $database);
        $connection->disconnect();
        $connection->setDatabaseName($database);

        $this->baseCachePrefix ??= (string) config('cache.prefix');
        config()->set('cache.prefix', $tenant === null ? $this->baseCachePrefix : $this->baseCachePrefix.'t'.$tenant->id.'_');

        // Outside a request (queue worker, scheduler, console) the tenant's primary host is the base of every absolute
        // URL (mail, queued jobs); it keeps the scheme of app.url and drops any port. Inside a request the request's own
        // host stays the root, so a secondary domain keeps its own links, cookies and asset URLs.
        $root = $host !== null && $this->app->runningInConsole() ? parse_url((string) config('app.url'), PHP_URL_SCHEME).'://'.$host : null;
        URL::forceRootUrl($root);

        Cache::forgetDriver();
        $this->app->forgetInstance('cache.store');

        if ($this->app->resolved(RateLimiter::class)) {
            // TenancyServiceProvider swaps the framework's instance for a TenantRateLimiter.
            $this->app->make(RateLimiter::class)->rebind(Cache::driver(config('cache.limiter')));
        }

        Mail::purge();
        Storage::forgetDisk(array_keys(config('filesystems.disks')));
    }
}
