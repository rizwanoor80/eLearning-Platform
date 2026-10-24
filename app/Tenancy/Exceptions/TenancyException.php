<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Tenancy refused to carry on: a query with no tenant set, a switch inside a transaction, a job with no tenant.
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

    public static function jobWithoutTenant(string $job): self
    {
        return new self("Queued job [{$job}] carries no tenant: refusing to run it.");
    }
}
