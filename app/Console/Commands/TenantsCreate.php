<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Tenancy\DatabaseProvisioner;
use App\Tenancy\Tenant;
use App\Tenancy\TenantDomain;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class TenantsCreate extends Command
{
    protected $signature = 'tenants:create {slug : Lower-case letters, digits and hyphens}
        {--name= : Display name (default: the slug)}
        {--host= : Primary host (default: <slug>.test)}
        {--template= : Clone this database instead of running the migrations}';

    protected $description = 'Create a tenant: its database, the v1 schema and its registry rows (ADR-032). A failure leaves nothing behind.';

    public function handle(DatabaseProvisioner $provisioner, TenantManager $manager): int
    {
        if (! $manager->enabled()) {
            $this->error('Tenancy is disabled (TENANCY_ENABLED=false).');

            return self::FAILURE;
        }

        $slug = (string) $this->argument('slug');

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,40}[a-z0-9])?$/', $slug) !== 1) {
            $this->error('The slug must be lower-case letters, digits and hyphens.');

            return self::FAILURE;
        }

        $host = strtolower(trim((string) ($this->option('host') ?: $slug.'.test')));

        if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1) {
            $this->error("[{$host}] is not a valid host name.");

            return self::FAILURE;
        }

        $database = config('tenancy.database_prefix').str_replace('-', '_', $slug);
        $template = $this->option('template') ?: config('tenancy.template_database');

        try {
            $provisioner->guardName($database);
            $template === null || $provisioner->guardName($template);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($template !== null && (! $provisioner->exists($template) || Tenant::query()->where('database', $template)->exists())) {
            $this->error("Template [{$template}] must be an existing database that is not a tenant's.");

            return self::FAILURE;
        }

        if (Tenant::query()->where('slug', $slug)->exists() || $provisioner->exists($database)) {
            $this->error("Tenant [{$slug}] or database [{$database}] already exists.");

            return self::FAILURE;
        }

        if (TenantDomain::query()->where('host', $host)->exists()) {
            $this->error("Host [{$host}] already belongs to a tenant.");

            return self::FAILURE;
        }

        $started = hrtime(true);
        $created = false;
        $tenant = null;

        try {
            $provisioner->create($database, $template);
            $created = true;

            // Provisioning is served by nothing: the host is not reachable until the schema is in place.
            $tenant = Tenant::query()->create([
                'slug' => $slug,
                'name' => $this->option('name') ?: Str::headline($slug),
                'database' => $database,
                'status' => TenantStatus::Provisioning,
            ]);
            $tenant->domains()->create(['host' => $host, 'is_primary' => true, 'verified_at' => now()]);

            if ($template === null) {
                $provisioner->migrate($tenant);
            }

            $tenant->update(['status' => TenantStatus::Active]);
        } catch (Throwable $e) {
            $this->error("Creating tenant [{$slug}] failed and was rolled back: ".$e->getMessage());

            $tenant?->domains()->delete();
            $tenant?->delete();
            $created && $provisioner->drop($database);

            return self::FAILURE;
        }

        $this->info(sprintf('Tenant [%s] created in %d ms (%s).', $slug, (hrtime(true) - $started) / 1e6, $template === null ? 'migrated' : 'from template'));

        return self::SUCCESS;
    }
}
