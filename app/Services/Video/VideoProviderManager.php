<?php

namespace App\Services\Video;

use App\Enums\VideoProviderCode;
use App\Models\VideoProvider;

/**
 * Resolves a `VideoRoomProvider` from the registry (invariant 16) — never hard-wired.
 *
 * `active()` is for new rooms. `forCode()` is for a lesson that already has a room and for an
 * incoming webhook: both must keep working with the provider that made the room after an admin
 * switches the active one.
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

        return match ($row->providerCode()) {
            VideoProviderCode::Daily => new DailyVideoProvider($credentials),
            VideoProviderCode::Fake => new FakeVideoProvider($credentials),
            default => throw new VideoProviderException("There is no driver for {$row->code}."),
        };
    }
}
