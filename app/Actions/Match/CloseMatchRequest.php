<?php

namespace App\Actions\Match;

use App\Actions\RecordAuditLog;
use App\Enums\MatchRequestStatus;
use App\Exceptions\MatchRequestException;
use App\Models\MatchRequest;
use App\Models\User;

class CloseMatchRequest
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function __invoke(User $admin, MatchRequest $request): void
    {
        if ($request->status === MatchRequestStatus::Closed) {
            throw new MatchRequestException('This request is already closed.');
        }

        $before = ['status' => $request->status->value];

        $request->forceFill(['status' => MatchRequestStatus::Closed, 'handled_by' => $admin->id])->save();

        ($this->recordAuditLog)($admin, 'match_request.closed', $request, $before, ['status' => MatchRequestStatus::Closed->value]);
    }
}
