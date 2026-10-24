<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Tenancy\DatabaseProvisioner;
use App\Tenancy\Tenant;
use Illuminate\Console\Command;
use Throwable;

class TenantsMigrate extends Command
{
    protected $signature = 'tenants:migrate {--tenant= : Only this slug}';

    protected $description = 'Run the pending migrations on every tenant database; the first failure stops and names the tenant.';

    public function handle(DatabaseProvisioner $provisioner): int
    {
        $tenants = Tenant::query()
            ->where('status', '!=', TenantStatus::Closed)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')->get();

        foreach ($tenants as $tenant) {
            $started = hrtime(true);

            try {
                $provisioner->migrate($tenant);
            } catch (Throwable $e) {
                $this->error("Migration failed on tenant [{$tenant->slug}]: ".$e->getMessage());

                return self::FAILURE;
            }

            $this->line(sprintf('%s: ok (%d ms)', $tenant->slug, (hrtime(true) - $started) / 1e6));
        }

        $this->info($tenants->count().' tenant(s) migrated.');

        return self::SUCCESS;
    }
}
