<?php

namespace App\Services\Video;

use App\Enums\VideoParticipant;
use Carbon\CarbonInterface;

/**
 * The provider for local, CI and rehearsal: no network, deterministic output. It refuses to be
 * the active provider in production (`VideoProvider::activationProblem()`, R125). It speaks
 * Daily's webhook shape, signed as `hmac_sha256("<X-Webhook-Timestamp>.<body>", webhook_secret)`
 * in hex, so the endpoint's verify, dedupe and parse path can be exercised end to end.
 */
class FakeVideoProvider implements VideoRoomProvider
{
    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(private readonly array $credentials) {}

    public function createRoom(string $roomName, CarbonInterface $expiresAt): VideoRoom
    {
        return new VideoRoom($roomName, 'https://video.fake.test/'.$roomName);
    }

    public function joinToken(string $roomName, VideoParticipant $participant, string $displayName, CarbonInterface $expiresAt): string
    {
        return 'fake.'.$roomName.'.'.$participant->value;
    }

    public function closeRoom(string $roomName): void {}

    public function verifyWebhook(string $payload, array $headers): bool
    {
        $secret = $this->credentials['webhook_secret'] ?? '';
        $signature = $headers['x-webhook-signature'] ?? '';
        $timestamp = $headers['x-webhook-timestamp'] ?? '';

        if ($secret === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > (int) config('video.webhook_tolerance_seconds')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$payload, $secret), $signature);
    }

    public function parseWebhook(string $payload): ?AttendanceEvent
    {
        return WebhookPayload::parse($payload);
    }
}
