<?php

namespace App\Services\Video;

use App\Enums\VideoParticipant;
use Carbon\CarbonInterface;

/**
 * What the lesson room needs from a video provider (PRD §9). Drivers: Daily, and a fake for local,
 * CI and rehearsal. Resolve the active one through `VideoProviderManager`; a lesson's own provider
 * through `VideoProviderManager::forCode()`.
 */
interface VideoRoomProvider
{
    /**
     * Creates the room, or returns the existing one when a room of this name already exists — so
     * a job that runs twice never makes a second room.
     *
     * @throws VideoProviderException
     */
    public function createRoom(string $roomName, CarbonInterface $expiresAt): VideoRoom;

    /**
     * A join token good for one party, ending at `$expiresAt`. The participant id it carries is
     * what a webhook later reports back.
     *
     * @throws VideoProviderException
     */
    public function joinToken(string $roomName, VideoParticipant $participant, string $displayName, CarbonInterface $expiresAt): string;

    /**
     * Closes the room. Closing one that is already gone is not an error.
     *
     * @throws VideoProviderException
     */
    public function closeRoom(string $roomName): void;

    /**
     * Whether a webhook body was signed by this provider. `$headers` is keyed by lower-case name.
     *
     * @param  array<string, string>  $headers
     */
    public function verifyWebhook(string $payload, array $headers): bool;

    /**
     * The attendance event in a verified body, or null for any event kind we do not use.
     */
    public function parseWebhook(string $payload): ?AttendanceEvent;
}
