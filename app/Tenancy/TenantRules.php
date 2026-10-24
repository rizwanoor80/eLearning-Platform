<?php

namespace App\Tenancy;

/** What a tenant slug and a tenant host may look like; shared by the commands that register tenants. */
final class TenantRules
{
    public static function validSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,40}[a-z0-9])?$/', $slug) === 1;
    }

    public static function validHost(string $host): bool
    {
        return preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host) === 1;
    }

    public static function normaliseHost(string $host): string
    {
        return strtolower(trim($host));
    }
}
