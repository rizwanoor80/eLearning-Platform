<?php

namespace App\Providers;

use App\Tenancy\TenantManager;
use App\Tenancy\TenantQueue;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantManager::class);
        $this->app->singleton(TenantQueue::class);

        // Fail closed from the first query: until a tenant is initialised the tenant connection names a database
        // that does not exist.
        if (config('tenancy.enabled')) {
            config()->set('database.connections.'.config('tenancy.tenant_connection').'.database', config('tenancy.no_tenant_database'));
        }
    }

    public function boot(): void
    {
        $this->app->make(TenantQueue::class)->register();
    }
}
