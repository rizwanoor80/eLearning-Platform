<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * R138: a suspended account (Safeguarding queue, `SuspendAccount`/`DisableAdminUser`) must not go
 * on using a session that started before the suspension. Those actions already delete every
 * `sessions` row for the target and rotate `remember_token`, so another device is cut off on its
 * next request regardless of this middleware; this is what ends the one request that is already
 * authenticated in-process when the suspension happens, and every request after, on any device
 * whose session survives some other way. Re-authenticating afterwards is refused separately, by
 * `Fortify::authenticateUsing` (`FortifyServiceProvider`), which checks `status` before issuing a
 * new session at all — this middleware only ever sees a session that predates the suspension.
 *
 * Two named-route families are excluded because they already handle a suspended party correctly,
 * on their own, and a blanket redirect here would pre-empt that:
 * - `admin.*` — `access-admin-area` (`AppServiceProvider::configureGates()`) already checks
 *   `status === Active` and answers 403, which `TutorDocumentAccessTest` (R28) asserts by name.
 * - `messages.*` — a suspended party's conversation is deliberately still viewable, closed, with a
 *   "This conversation is closed." notice (`MessagingPagesTest`), not hidden behind a login wall.
 *
 * A guest request (no user yet) passes through untouched.
 */
class EnsureAccountActive
{
    private const EXCLUDED_ROUTE_PREFIXES = ['admin.', 'messages.'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->status !== UserStatus::Active && ! $this->routeIsExcluded($request)) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'This account is no longer active.');
        }

        return $next($request);
    }

    private function routeIsExcluded(Request $request): bool
    {
        $name = $request->route()?->getName();

        if ($name === null) {
            return false;
        }

        foreach (self::EXCLUDED_ROUTE_PREFIXES as $prefix) {
            if (Str::startsWith($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
