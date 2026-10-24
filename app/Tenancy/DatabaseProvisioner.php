<?php

namespace App\Tenancy;

use Database\Seeders\TenantReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Creates, migrates and drops tenant databases. CREATE DATABASE cannot run on the database it names, so it goes
 * through a throwaway connection to the server's `postgres` database. Needs the CREATEDB privilege (ADR-032).
 */
class DatabaseProvisioner
{
    private const ADMIN = 'tenancy_admin';

    public function exists(string $database): bool
    {
        return DB::connection($this->admin())->table('pg_database')->where('datname', $database)->exists();
    }

    public function create(string $database, ?string $template = null): void
    {
        $this->guardName($database);
        $sql = 'CREATE DATABASE "'.$database.'"';

        if ($template !== null) {
            $this->guardName($template);
            $sql .= ' TEMPLATE "'.$template.'"';
        }

        DB::connection($this->admin())->statement($sql);
    }

    public function drop(string $database): void
    {
        $this->guardName($database);

        $protected = ['postgres', 'template0', 'template1', (string) config('database.connections.'.config('tenancy.central_connection').'.database')];

        if (in_array($database, $protected, true)) {
            throw new InvalidArgumentException("Refusing to drop protected database [{$database}].");
        }

        DB::connection($this->admin())->statement('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
    }

    /** Run the v1 migrations (the default path) against a tenant's database. */
    public function migrate(Tenant $tenant): string
    {
        return app(TenantManager::class)->run($tenant, function (): string {
            $exit = Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
            $output = trim(Artisan::output());

            if ($exit !== 0) {
                throw new RuntimeException("migrate exited {$exit}: ".$output);
            }

            return $output;
        });
    }

    /** Seed the reference data (never demo data) into a tenant's database; idempotent. */
    public function seedReference(Tenant $tenant): void
    {
        app(TenantManager::class)->run($tenant, function (): void {
            $exit = Artisan::call('db:seed', ['--class' => TenantReferenceSeeder::class, '--force' => true, '--no-interaction' => true]);

            if ($exit !== 0) {
                throw new RuntimeException("db:seed exited {$exit}: ".trim(Artisan::output()));
            }
        });
    }

    private function admin(): string
    {
        config()->set('database.connections.'.self::ADMIN, [
            ...config('database.connections.'.config('tenancy.central_connection')),
            'database' => 'postgres',
        ]);

        return self::ADMIN;
    }

    public function guardName(string $name): void
    {
        if (preg_match('/^[a-z0-9_]{1,63}$/', $name) !== 1 || $name === config('tenancy.no_tenant_database')) {
            throw new InvalidArgumentException("Unsafe database name [{$name}].");
        }
    }
}
