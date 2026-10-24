<?php

namespace App\Console\Commands;

use App\Actions\Tenancy\CreateTenantAdmin;
use App\Enums\TenantStatus;
use App\Tenancy\DatabaseProvisioner;
use App\Tenancy\Tenant;
use App\Tenancy\TenantDomain;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantRules;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class TenantsCreate extends Command
{
    protected $signature = 'tenants:create {slug : Lower-case letters, digits and hyphens}
        {--name= : Display name (default: the slug)}
        {--host= : Primary host (default: <slug>.test)}
        {--template= : Clone this database instead of running the migrations}
        {--admin-email= : Create the first administrator with this address (their password is set through "forgot password")}
        {--admin-name= : The administrator\'s name (default: Admin)}';

    protected $description = 'Create a tenant: its database, the v1 schema, the reference data (never demo data), an optional first administrator and its registry rows (ADR-032). A failure leaves nothing behind.';

    public function handle(DatabaseProvisioner $provisioner, TenantManager $manager, CreateTenantAdmin $createAdmin): int
    {
        if (! $manager->enabled()) {
            $this->error('Tenancy is disabled (TENANCY_ENABLED=false).');

            return self::FAILURE;
        }

        $slug = (string) $this->argument('slug');

        if (! TenantRules::validSlug($slug)) {
            $this->error('The slug must be lower-case letters, digits and hyphens.');

            return self::FAILURE;
        }

        $host = TenantRules::normaliseHost((string) ($this->option('host') ?: $slug.'.test'));

        if (! TenantRules::validHost($host)) {
            $this->error("[{$host}] is not a valid host name.");

            return self::FAILURE;
        }

        $adminEmail = $this->option('admin-email') ? strtolower(trim((string) $this->option('admin-email'))) : null;

        if ($adminEmail !== null && filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('The administrator address is not a valid email address.');

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

        // Only the configured template database: any other name could be the central registry or a live database.
        if ($template !== null && ($template !== config('tenancy.template_database') || ! $provisioner->exists($template) || Tenant::query()->where('database', $template)->exists())) {
            $this->error("Template [{$template}] must be the existing TENANCY_TEMPLATE_DATABASE and not a tenant's.");

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

            $provisioner->seedReference($tenant);

            if ($adminEmail !== null) {
                $createAdmin($tenant, $adminEmail, (string) ($this->option('admin-name') ?: 'Admin'));
            }

            $tenant->update(['status' => TenantStatus::Active]);
        } catch (Throwable $e) {
            $this->error("Creating tenant [{$slug}] failed and was rolled back: ".$e->getMessage());

            foreach ([fn () => $tenant?->admins()->delete(), fn () => $tenant?->domains()->delete(), fn () => $tenant?->delete(), fn () => $created && $provisioner->drop($database)] as $cleanup) {
                try {
                    $cleanup();
                } catch (Throwable $cleanupFailure) {
                    $this->error('Cleanup step failed: '.$cleanupFailure->getMessage());
                }
            }

            return self::FAILURE;
        }

        $this->info(sprintf('Tenant [%s] created in %d ms (%s)%s.', $slug, (hrtime(true) - $started) / 1e6, $template === null ? 'migrated' : 'from template', $adminEmail === null ? '' : ', administrator created'));

        return self::SUCCESS;
    }
}
