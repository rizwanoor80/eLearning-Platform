<?php

namespace App\Actions\Ops;

use App\Actions\RecordAuditLog;
use App\Enums\Role;
use App\Exceptions\OpsStatusTokenException;
use App\Models\OpsStatusToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * R193: switches `GET /ops/status` off by revoking the active token. The row stays as the record that
 * it existed; a revoked token answers exactly like a wrong one (404). A second call finds nothing active
 * and does nothing.
 */
class RevokeOpsStatusToken
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @return bool whether a token was revoked
     *
     * @throws OpsStatusTokenException when the actor is not an admin
     */
    public function __invoke(User $actor): bool
    {
        if ($actor->role !== Role::Admin) {
            throw new OpsStatusTokenException('Only an admin can revoke the status token.');
        }

        return DB::transaction(function () use ($actor): bool {
            $token = OpsStatusToken::query()->active()->lockForUpdate()->first();

            if ($token === null) {
                return false;
            }

            $token->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id])->save();

            $this->recordAuditLog->__invoke($actor, 'ops_status_token.revoked', $token, ['revoked_at' => null], ['revoked_at' => $token->revoked_at?->toIso8601String()]);

            return true;
        });
    }
}
