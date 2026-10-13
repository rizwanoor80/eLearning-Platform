<?php

namespace App\Http\Middleware;

use App\Models\OpsStatusToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * R193: the only gate in front of `GET /ops/status`. A request passes when it carries
 * `Authorization: Bearer <token>`, the token's SHA-256 hash matches the *active* row, and — if the row
 * has an allow-list — the caller's address is on it. Everything else gets the same 404 with the same
 * body: a missing header, a wrong token, a revoked or rotated-away token, a disallowed address. The
 * response never says which, so the *reason* cannot be probed. The route itself can still be told from
 * an unknown path (the throttle in front adds rate-limit headers, the body is always JSON, and a 31st
 * call in a minute is a 429); R193 asks for 404 rather than 401, not for an invisible route.
 *
 * What is logged on a rejection is the address and the reason code — never the header or the token.
 * The request has no session and no cookies (the route is outside the `web` group, `routes/ops.php`).
 */
class AuthenticateOpsStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if ($plain === null || $plain === '') {
            return $this->reject($request, 'no_token');
        }

        // Looked up by hash, so no comparison of the secret itself happens in PHP at all.
        $token = OpsStatusToken::query()
            ->active()
            ->where('token_hash', OpsStatusToken::hashOf($plain))
            ->first();

        if ($token === null) {
            return $this->reject($request, 'unknown_or_revoked');
        }

        if (! $token->allowsIp($request->ip())) {
            return $this->reject($request, 'ip_not_allowed');
        }

        $request->attributes->set('ops_status_token', $token);

        return $next($request);
    }

    private function reject(Request $request, string $reason): JsonResponse
    {
        Log::warning('ops status request rejected', ['reason' => $reason, 'ip' => $request->ip()]);

        return response()->json(['message' => 'Not found.'], 404);
    }
}
