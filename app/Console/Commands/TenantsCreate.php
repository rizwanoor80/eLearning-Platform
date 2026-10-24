<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Tenancy\DatabaseProvisioner;
use App\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TenantsCreate extends Command
{
    protected $signature = 'tenants:create {slug : Lower-case letters, digits and hyphens}
        {--name= : Display name (default: the slug)}
        {--host= : Primary host (default: <slug>.test)}
        {--template= : Clone this database instead of running the migrations}';

    protected $description = 'Create a tenant: its database, the v1 schema and its registry rows (spike, ADR-032).';

    public function handle(DatabaseProvisioner $provisioner): int
    {
        $slug = (string) $this->argument('slug');

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,40}[a-z0-9])?$/', $slug) !== 1) {
            $this->error('The slug must be lower-case letters, digits and hyphens.');

            return self::FAILURE;
        }

        $database = config('tenancy.database_prefix').str_replace('-', '_', $slug);
        $template = $this->option('template') ?: config('tenancy.template_database');

        if (Tenant::query()->where('slug', $slug)->exists() || $provisioner->exists($database)) {
            $this->error("Tenant [{$slug}] or database [{$database}] already exists.");

            return self::FAILURE;
        }

        $started = hrtime(true);
        $provisioner->create($database, $template);

        $tenant = Tenant::query()->create([
            'slug' => $slug,
            'name' => $this->option('name') ?: Str::headline($slug),
            'database' => $database,
            'status' => TenantStatus::Active,
        ]);
        $tenant->domains()->create(['host' => $this->option('host') ?: $slug.'.test', 'is_primary' => true, 'verified_at' => now()]);

        if ($template === null) {
            $provisioner->migrate($tenant);
        }

        $this->info(sprintf('Tenant [%s] created in %d ms (%s).', $slug, (hrtime(true) - $started) / 1e6, $template === null ? 'migrated' : 'from template'));

        return self::SUCCESS;
    }
}
