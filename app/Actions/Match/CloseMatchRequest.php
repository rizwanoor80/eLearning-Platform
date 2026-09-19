<?php

namespace App\Actions\Match;

use App\Actions\RecordAuditLog;
use App\Enums\MatchRequestStatus;
use App\Exceptions\MatchRequestException;
use App\Models\MatchRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closing locks the request row first, like SuggestTutors, so a close and a
 * suggestion racing each other are serialised: whichever runs second sees the
 * other's status and is refused or applied on top of it, never interleaved.
 */
class CloseMatchRequest
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function __invoke(User $admin, MatchRequest $request): void
    {
        DB::transaction(function () use ($admin, $request): void {
            $locked = MatchRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status === MatchRequestStatus::Closed) {
                throw new MatchRequestException('This request is already closed.');
            }

            $before = ['status' => $locked->status->value];

            $locked->forceFill(['status' => MatchRequestStatus::Closed, 'handled_by' => $admin->id])->save();

            ($this->recordAuditLog)($admin, 'match_request.closed', $locked, $before, ['status' => MatchRequestStatus::Closed->value]);

            $request->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
