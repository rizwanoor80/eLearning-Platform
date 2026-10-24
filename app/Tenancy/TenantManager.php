<?php

namespace App\Tenancy;

use App\Tenancy\Exceptions\TenancyException;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Holds the current tenant and repoints everything that is per-tenant when it changes (ADR-032).
 *
 * The tenant connection keeps the name `pgsql` and is purged on every switch, so a long-lived worker holds exactly one
 * tenant connection. With no tenant it points at a database that does not exist. Services built from config before
 * the switch (cache store, mailer, disks, URL root) are forgotten so the next use rebuilds them with the new config.
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
            $previous === null ? $this->end() : $this->initialize($previous);
        }
    }

    /** The database a switch lands on: the tenant's, or the one that does not exist. */
    private function apply(?Tenant $tenant): void
    {
        $connection = config('tenancy.tenant_connection');

        if ($this->app->resolved('db') && DB::connection($connection)->transactionLevel() > 0) {
            throw TenancyException::switchInTransaction();
        }

        $this->current = $tenant;

        config()->set("database.connections.{$connection}.database", $tenant->database ?? config('tenancy.no_tenant_database'));
        DB::purge($connection);

        $this->baseCachePrefix ??= (string) config('cache.prefix');
        config()->set('cache.prefix', $tenant === null ? $this->baseCachePrefix : $this->baseCachePrefix.'t'.$tenant->id.'_');

        // The tenant's primary host is the base of every absolute URL built outside a request (mail, queued jobs).
        $host = $tenant?->domains()->where('is_primary', true)->value('host');
        $root = $host !== null ? parse_url((string) config('app.url'), PHP_URL_SCHEME).'://'.$host : null;
        URL::forceRootUrl($root);

        Cache::forgetDriver();
        Mail::purge();
        Storage::forgetDisk(array_keys(config('filesystems.disks')));
    }
}
