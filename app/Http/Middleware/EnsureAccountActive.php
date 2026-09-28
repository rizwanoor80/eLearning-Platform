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
 * Two named-route families are excluded so this middleware's blanket redirect does not pre-empt a
 * more specific check that already exists on that family. The exclusion itself grants nothing —
 * each excluded family is still protected, just by something else. Despite the name, `admin.*`
 * here is **not** the Filament admin panel: the panel's routes are named `filament.admin.*` and
 * run through `AdminPanelProvider`'s own separate middleware stack, which never includes this
 * middleware at all — the panel is protected by the `access-admin-area` gate
 * (`AppServiceProvider::configureGates()`) via Filament's own `authMiddleware`, not by anything
 * excluded here.
 * - `admin.*` matches exactly one route, `admin.documents.show` (`routes/web.php`), which sits in
 *   the default `web` group and so does reach this middleware. That route already carries
 *   `can:access-admin-area` directly, which checks `status === Active` and answers 403 — asserted
 *   by `TutorDocumentAccessTest` (R28). The exclusion only stops this middleware's blanket
 *   redirect-to-`login` from firing first and pre-empting that gate's own 403.
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
