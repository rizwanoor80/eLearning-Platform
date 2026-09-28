<?php

namespace App\Services\Video;

use App\Enums\VideoProviderCode;
use App\Models\VideoProvider;
use App\Providers\PaymentGatewayServiceProvider;

/**
 * Resolves a `VideoRoomProvider` from the registry (invariant 16) — never hard-wired.
 *
 * `active()` is for new rooms. `forCode()` is for a lesson that already has a room and for an
 * incoming webhook: both must keep working with the provider that made the room after an admin
 * switches the active one.
 *
 * `forRow()` re-checks the fake environment allow-list (R131, ADR-022) independently of
 * `VideoProvider::activationProblem()`: that guard only runs when a row is saved with
 * `is_active = true`, so a row already active before an environment change (or written by a raw
 * update, as the one-active-row database constraint test shows is possible below the model) would
 * otherwise still resolve to `FakeVideoProvider` with no code-path left to stop it. This is the
 * single place every resolution path (`active()`, `forCode()`, `forRow()` itself) funnels through.
 */
class VideoProviderManager
{
    public function activeRow(): ?VideoProvider
    {
        return VideoProvider::query()->active()->first();
    }

    /**
     * @throws NoActiveVideoProvider
     */
    public function active(): VideoRoomProvider
    {
        $row = $this->activeRow();

        if ($row === null) {
            throw new NoActiveVideoProvider('No video provider is active. An admin must activate one under Video providers.');
        }

        return $this->forRow($row);
    }

    /**
     * @throws VideoProviderException
     */
    public function forCode(string $code): VideoRoomProvider
    {
        $row = VideoProvider::query()->where('code', $code)->first();

        if ($row === null) {
            throw new VideoProviderException("No video provider is registered as {$code}.");
        }

        return $this->forRow($row);
    }

    /**
     * @throws VideoProviderException
     */
    public function forRow(VideoProvider $row): VideoRoomProvider
    {
        $credentials = $row->credentials ?? [];
        $code = $row->providerCode();

        if ($code === VideoProviderCode::Fake && ! app()->environment(PaymentGatewayServiceProvider::FAKE_ENVIRONMENTS)) {
            throw new VideoProviderException('The fake video provider cannot run outside local, testing and rehearsal.');
        }

        return match ($code) {
            VideoProviderCode::Daily => new DailyVideoProvider($credentials),
            VideoProviderCode::Fake => new FakeVideoProvider($credentials),
            default => throw new VideoProviderException("There is no driver for {$row->code}."),
        };
    }
}
