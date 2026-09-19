<?php

namespace App\Http\Middleware;

use App\Support\Facades\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route switch for an admin feature toggle (`features` settings group):
 * `->middleware('feature:reviews')` answers 404 while the toggle is off, so the
 * route does not exist as far as the visitor can tell. The setting is read per
 * request, so an admin's flip takes effect immediately.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(self::enabled($feature), 404);

        return $next($request);
    }

    public static function enabled(string $feature): bool
    {
        return (bool) Settings::get($feature);
    }
}
