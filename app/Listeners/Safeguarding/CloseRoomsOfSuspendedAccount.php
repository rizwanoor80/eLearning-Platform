<?php

namespace App\Listeners\Safeguarding;

use App\Actions\Video\CloseSuspendedAccountRooms;
use App\Events\Safeguarding\AccountSuspended;
use App\Models\User;
use App\Services\Video\VideoProviderException;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * R232: a suspension ends video access. Queued, so a slow or failing provider never holds up the admin's request;
 * a provider failure throws once every lesson has been tried, and the queue's retry closes the rest (closing a room
 * twice is not an error, and each lesson is audited once).
 */
class CloseRoomsOfSuspendedAccount implements ShouldQueue
{
    public function __construct(private CloseSuspendedAccountRooms $closeRooms) {}

    /**
     * @throws VideoProviderException
     */
    public function handle(AccountSuspended $event): void
    {
        $account = User::query()->find($event->accountId);

        if ($account === null) {
            return;
        }

        $failed = ($this->closeRooms)($account, $event->actorId === null ? null : User::query()->find($event->actorId))['failed'];

        if ($failed > 0) {
            throw new VideoProviderException("{$failed} room(s) of suspended account {$account->id} could not be closed.");
        }
    }
}
