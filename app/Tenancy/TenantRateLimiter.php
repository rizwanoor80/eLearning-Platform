<?php

namespace App\Tenancy;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;

/**
 * The framework's RateLimiter keeps the cache repository it was built with, and its named limiters (registered by
 * providers at boot) cannot be re-registered on a new instance. On a tenant switch the repository is replaced in place,
 * so throttle counters carry the new tenant's cache prefix (ADR-032).
 */
final class TenantRateLimiter extends RateLimiter
{
    public function rebind(Repository $cache): void
    {
        $this->cache = $cache;
    }
}
