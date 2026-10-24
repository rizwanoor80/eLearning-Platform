<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Tenancy refused to carry on: a query with no tenant set, a switch inside a transaction, a job with no tenant,
 * a command that would create the sentinel database.
 * Every one is a would-be cross-tenant leak, so every one fails closed.
 */
final class TenancyException extends RuntimeException
{
    public static function noTenant(): self
    {
        return new self('No tenant is initialised: refusing to touch the tenant database.');
    }

    public static function switchInTransaction(): self
    {
        return new self('Refusing to switch tenant inside an open database transaction.');
    }

    public static function disabled(): self
    {
        return new self('Tenancy is disabled (TENANCY_ENABLED=false): refusing to switch tenant.');
    }

    public static function misconfigured(string $why): self
    {
        return new self("Tenancy is enabled but misconfigured: {$why}");
    }

    public static function commandWithoutTenant(string $command): self
    {
        return new self("[{$command}] would run on the tenant connection with no tenant set. Use tenants:migrate / tenants:each, or migrate the registry with --database=central --path=database/migrations/central.");
    }

    public static function tenantNotRunnable(string $slug, string $status): self
    {
        return new self("Tenant [{$slug}] is {$status}: refusing to run its queued job.");
    }

    public static function jobWithoutTenant(string $job): self
    {
        return new self("Queued job [{$job}] carries no tenant: refusing to run it.");
    }
}
