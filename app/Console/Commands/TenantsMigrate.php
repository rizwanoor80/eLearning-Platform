<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Tenancy\DatabaseProvisioner;
use App\Tenancy\Tenant;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class TenantsMigrate extends Command
{
    protected $signature = 'tenants:migrate {--tenant= : Only this slug}';

    protected $description = 'Run the pending migrations (never seeders) on every active or suspended tenant database; the first failure stops and names the tenant.';

    public function handle(DatabaseProvisioner $provisioner): int
    {
        // Active and Suspended: a suspended tenant comes back one day and must be on the current schema. Closed ones are
        // frozen, and Provisioning ones belong to the tenants:create that is still building them.
        $tenants = Tenant::query()
            ->whereIn('status', [TenantStatus::Active, TenantStatus::Suspended])
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')->get();

        if ($tenants->isEmpty() && $this->option('tenant')) {
            $this->error('No migratable tenant ['.$this->option('tenant').'].');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $started = hrtime(true);

            try {
                // migrate --force would create a missing database; a registered tenant without one is an error.
                if (! $provisioner->exists($tenant->database)) {
                    throw new RuntimeException("database [{$tenant->database}] does not exist");
                }

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
