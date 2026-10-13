<?php

namespace App\Actions\Ops;

use App\Actions\RecordAuditLog;
use App\Enums\Role;
use App\Exceptions\OpsStatusTokenException;
use App\Models\OpsStatusToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * R193: changes the IP allow-list of the active status token without rotating the token. An empty
 * list means any address. Audited with the old and new list (addresses are not secrets).
 */
class UpdateOpsStatusAllowList
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @param  list<string>  $allowedIps  already parsed by `AllowedIps::parse()`
     *
     * @throws OpsStatusTokenException when the actor is not an admin or there is no active token
     */
    public function __invoke(User $actor, array $allowedIps): OpsStatusToken
    {
        if ($actor->role !== Role::Admin) {
            throw new OpsStatusTokenException('Only an admin can change the status token.');
        }

        return DB::transaction(function () use ($actor, $allowedIps): OpsStatusToken {
            $token = OpsStatusToken::query()->active()->lockForUpdate()->first();

            if ($token === null) {
                throw new OpsStatusTokenException('There is no active status token to change. Generate one first.');
            }

            $before = $token->allowed_ips;
            $after = $allowedIps === [] ? null : $allowedIps;

            if ($before === $after) {
                return $token;
            }

            $token->forceFill(['allowed_ips' => $after])->save();

            $this->recordAuditLog->__invoke($actor, 'ops_status_token.allow_list_changed', $token, ['allowed_ips' => $before], ['allowed_ips' => $after]);

            return $token;
        });
    }
}
