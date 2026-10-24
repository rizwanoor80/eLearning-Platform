<?php

namespace App\Console\Commands;

use App\Tenancy\Tenant;
use Illuminate\Console\Command;

class TenantsUnregister extends Command
{
    protected $signature = 'tenants:unregister {slug} {--yes : Confirm}';

    protected $description = 'Remove a tenant from the registry (its hosts and contacts). The tenant database is never dropped or changed.';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('slug'))->first();

        if ($tenant === null) {
            $this->error('No tenant ['.$this->argument('slug').'].');

            return self::FAILURE;
        }

        if (! $this->option('yes')) {
            $this->error("Unregistering [{$tenant->slug}] stops its hosts answering. Re-run with --yes.");

            return self::FAILURE;
        }

        $database = $tenant->database;
        $tenant->delete();

        $this->info("Tenant [{$tenant->slug}] unregistered; database [{$database}] is untouched.");

        return self::SUCCESS;
    }
}
