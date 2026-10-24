<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantDomain;
use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the request host and switches to it. An unknown host is a 404 and a suspended or closed
 * tenant a 503, before anything else touches a database. Never ends tenancy: terminating callbacks and
 * after-response work still belong to this tenant, and a PHP-FPM process serves one request (Octane is unsupported).
 */
class IdentifyTenant
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenants->enabled()) {
            return $next($request);
        }

        $domain = TenantDomain::query()->with('tenant')->where('host', strtolower($request->getHost()))->first();

        abort_if($domain === null || $domain->tenant === null, 404);
        abort_unless($domain->tenant->isActive(), 503);

        $this->tenants->initialize($domain->tenant);

        return $next($request);
    }
}
