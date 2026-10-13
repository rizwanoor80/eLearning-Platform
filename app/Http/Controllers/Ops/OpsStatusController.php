<?php

namespace App\Http\Controllers\Ops;

use App\Actions\RecordAuditLog;
use App\Http\Controllers\Controller;
use App\Models\OpsStatusToken;
use App\Services\Ops\OpsStatusReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /ops/status` (R193): the operator status document for a deploy that nobody can ssh into.
 * Reached only through `AuthenticateOpsStatus`; every successful read is audited with the caller's
 * address (the token row is the subject — no user acts here) and stamps the token's `last_used_at`.
 */
class OpsStatusController extends Controller
{
    public function __invoke(Request $request, OpsStatusReport $report, RecordAuditLog $recordAuditLog): JsonResponse
    {
        /** @var OpsStatusToken $token */
        $token = $request->attributes->get('ops_status_token');

        $document = $report->build();

        $token->forceFill(['last_used_at' => now()])->save();
        $recordAuditLog(null, 'ops_status_token.read', $token, null, ['ip' => $request->ip()]);

        return response()
            ->json($document)
            ->header('Cache-Control', 'no-store, private');
    }
}
