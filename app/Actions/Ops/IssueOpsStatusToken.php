<?php

namespace App\Actions\Ops;

use App\Actions\RecordAuditLog;
use App\Enums\Role;
use App\Exceptions\OpsStatusTokenException;
use App\Models\OpsStatusToken;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * R193: generates the bearer token for `GET /ops/status`. Used both to create the first token and to
 * rotate: any active token is revoked and the new row inserted in one transaction, so there is never
 * a moment with two (the partial unique index would refuse it) or, for a rotation, with none. The
 * plaintext is returned once and kept nowhere — the row holds its SHA-256 hash, and the audit row
 * names the token id and the allow-list, never the token or its hash.
 */
class IssueOpsStatusToken
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @param  list<string>|null  $allowedIps  already parsed by `AllowedIps::parse()`
     *
     * @throws OpsStatusTokenException when the actor is not an admin, or another admin changed the token at the same moment
     */
    public function __invoke(User $actor, ?array $allowedIps = null): IssuedOpsStatusToken
    {
        if ($actor->role !== Role::Admin) {
            throw new OpsStatusTokenException('Only an admin can issue the status token.');
        }

        $plain = Str::random(64);

        try {
            $token = DB::transaction(function () use ($actor, $allowedIps, $plain): OpsStatusToken {
                $previous = OpsStatusToken::query()->active()->lockForUpdate()->first();

                $previous?->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id])->save();

                $token = OpsStatusToken::query()->create([
                    'token_hash' => OpsStatusToken::hashOf($plain),
                    'allowed_ips' => $allowedIps === [] ? null : $allowedIps,
                    'created_by' => $actor->id,
                ]);

                $this->recordAuditLog->__invoke(
                    $actor,
                    $previous === null ? 'ops_status_token.issued' : 'ops_status_token.rotated',
                    $token,
                    $previous === null ? null : ['replaced_token_id' => $previous->id],
                    ['allowed_ips' => $token->allowed_ips],
                );

                return $token;
            });
        } catch (UniqueConstraintViolationException) {
            throw new OpsStatusTokenException('Another admin changed the status token at the same moment. Reload the page and check it.');
        }

        return new IssuedOpsStatusToken($token, $plain);
    }
}
