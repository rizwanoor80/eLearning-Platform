<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\User;
use App\Tenancy\DatabaseProvisioner;
use App\Tenancy\Tenant;
use App\Tenancy\TenantAdmin;
use App\Tenancy\TenantDomain;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class TenantsAdopt extends Command
{
    protected $signature = 'tenants:adopt {slug : Lower-case letters, digits and hyphens}
        {--from= : Must be "current": the database the application used before tenancy (DB_DATABASE)}
        {--host= : Primary host the tenant answers on}
        {--name= : Display name (default: the slug)}';

    protected $description = 'Register the existing database as a tenant without copying or changing a row of it (R291). Undo with tenants:unregister.';

    public function handle(DatabaseProvisioner $provisioner, TenantManager $manager): int
    {
        if (! $manager->enabled()) {
            $this->error('Tenancy is disabled (TENANCY_ENABLED=false).');

            return self::FAILURE;
        }

        if ($this->option('from') !== 'current') {
            $this->error('Only --from=current is supported: the database named by DB_DATABASE.');

            return self::FAILURE;
        }

        $slug = (string) $this->argument('slug');
        $host = TenantRules::normaliseHost((string) $this->option('host'));
        $database = (string) config('tenancy.current_database');

        if (! TenantRules::validSlug($slug)) {
            $this->error('The slug must be lower-case letters, digits and hyphens.');

            return self::FAILURE;
        }

        if (! TenantRules::validHost($host)) {
            $this->error('--host is required and must be a valid host name.');

            return self::FAILURE;
        }

        try {
            $provisioner->guardName($database);
        } catch (\InvalidArgumentException $e) {
            $this->error('DB_DATABASE is not a database that can be adopted: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($database === (string) config('database.connections.'.config('tenancy.central_connection').'.database')) {
            $this->error('DB_DATABASE is the central registry; it cannot be a tenant.');

            return self::FAILURE;
        }

        if (! $provisioner->exists($database)) {
            $this->error("Database [{$database}] does not exist.");

            return self::FAILURE;
        }

        if (Tenant::query()->where('slug', $slug)->orWhere('database', $database)->exists()) {
            $this->error("Tenant [{$slug}] or database [{$database}] is already registered.");

            return self::FAILURE;
        }

        if (TenantDomain::query()->where('host', $host)->exists()) {
            $this->error("Host [{$host}] already belongs to a tenant.");

            return self::FAILURE;
        }

        $tenant = null;

        try {
            // Provisioning: served by nothing until the database has been looked at.
            $tenant = Tenant::query()->create([
                'slug' => $slug,
                'name' => $this->option('name') ?: ucfirst($slug),
                'database' => $database,
                'status' => TenantStatus::Provisioning,
            ]);
            $tenant->domains()->create(['host' => $host, 'is_primary' => true, 'verified_at' => now()]);

            // Read-only: it must be a v1 database, and what it holds is reported, never changed.
            [$users, $admins] = $manager->run($tenant, function (): array {
                foreach (['migrations', 'users', 'lessons', 'ledger_entries'] as $table) {
                    if (! Schema::hasTable($table)) {
                        throw new RuntimeException("table [{$table}] is missing: this is not a TrusTutor database");
                    }
                }

                return [User::query()->count(), User::query()->where('role', Role::Admin)->get(['name', 'email'])];
            });

            foreach ($admins as $admin) {
                TenantAdmin::query()->updateOrCreate(['tenant_id' => $tenant->getKey(), 'email' => strtolower((string) $admin->email)], ['name' => $admin->name]);
            }

            $tenant->update(['status' => TenantStatus::Active]);
        } catch (Throwable $e) {
            $this->error("Adopting [{$slug}] failed and was undone (the database was not touched): ".$e->getMessage());

            try {
                $tenant?->delete();
            } catch (Throwable $cleanupFailure) {
                $this->error('Cleanup step failed: '.$cleanupFailure->getMessage());
            }

            return self::FAILURE;
        }

        $this->info("Tenant [{$slug}] now answers on [{$host}]; its database was not changed ({$users} users, {$admins->count()} administrator(s)).");

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($appHost !== null && $appHost !== false && strtolower($appHost) !== $host) {
            $this->warn("[{$host}] differs from the host in APP_URL ([{$appHost}]): links already sent and webhooks addressed to another host will answer 404 until that host is registered too.");
        }

        $this->line('Run tenants:migrate for any pending migration. To undo: tenants:unregister '.$slug.' --yes (the database is left as it is).');

        return self::SUCCESS;
    }
}
