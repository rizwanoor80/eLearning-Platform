<?php

namespace App\Services\Video;

use App\Enums\VideoParticipant;
use App\Enums\VideoProviderCode;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Daily.co driver: embed and attendance webhooks. Built and tested against HTTP fakes only — no
 * Daily key is ever held here (R125). The wire details below (paths, payload keys, the webhook
 * signature scheme) are written from Daily's public documentation and are to be confirmed on the
 * first real key (ADR-017); they are confined to this class so a correction is one file.
 */
class DailyVideoProvider implements VideoRoomProvider
{
    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(private readonly array $credentials) {}

    public function createRoom(string $roomName, CarbonInterface $expiresAt): VideoRoom
    {
        $response = $this->request()->post('/rooms', [
            'name' => $roomName,
            'privacy' => 'private',
            'properties' => [
                'exp' => $expiresAt->getTimestamp(),
                'eject_at_room_exp' => true,
                'enable_chat' => true,
            ],
        ]);

        // A room of this name already exists: Daily answers 400, and the existing room is fetched
        // instead, so a second run never fails and never makes a second room.
        if ($response->status() === 400 && str_contains(strtolower((string) $response->json('info')), 'already exists')) {
            $response = $this->request()->get('/rooms/'.rawurlencode($roomName));
        }

        $this->ensureOk($response, 'create room');

        $name = $response->json('name');
        $url = $response->json('url');

        if (! is_string($name) || ! is_string($url)) {
            throw new VideoProviderException('Daily create room returned an unexpected response.');
        }

        return new VideoRoom($name, $url);
    }

    public function joinToken(string $roomName, VideoParticipant $participant, string $displayName, CarbonInterface $expiresAt): string
    {
        $response = $this->request()->post('/meeting-tokens', [
            'properties' => [
                'room_name' => $roomName,
                'user_id' => $participant->value,
                'user_name' => $displayName,
                'is_owner' => false,
                'exp' => $expiresAt->getTimestamp(),
                'eject_at_token_exp' => true,
            ],
        ]);

        $this->ensureOk($response, 'join token');

        $token = $response->json('token');

        if (! is_string($token) || $token === '') {
            throw new VideoProviderException('Daily join token returned an unexpected response.');
        }

        return $token;
    }

    public function closeRoom(string $roomName): void
    {
        $response = $this->request()->delete('/rooms/'.rawurlencode($roomName));

        if ($response->status() === 404) {
            return;
        }

        $this->ensureOk($response, 'close room');
    }

    /**
     * Daily signs `"<X-Webhook-Timestamp>.<raw body>"` with HMAC-SHA256, keyed by the base64-decoded
     * webhook secret, and sends the base64 of the digest in `X-Webhook-Signature`. The timestamp is
     * accepted in seconds or milliseconds (a value of 12+ digits is divided by 1000 before the
     * tolerance check); the original header string is still what gets signed either way, so a
     * millisecond stamp verifies against the same signature a seconds stamp would.
     */
    public function verifyWebhook(string $payload, array $headers): bool
    {
        $secret = $this->credentials['webhook_secret'] ?? '';
        $signature = $headers['x-webhook-signature'] ?? '';
        $timestamp = $headers['x-webhook-timestamp'] ?? '';
        $key = base64_decode($secret, true);

        if ($key === false || $key === '') {
            return $this->reject('no_key', $timestamp, $signature, $payload, $headers);
        }

        if (! ctype_digit($timestamp)) {
            return $this->reject('bad_timestamp', $timestamp, $signature, $payload, $headers);
        }

        $seconds = strlen($timestamp) >= 12 ? intdiv((int) $timestamp, 1000) : (int) $timestamp;

        if (abs(time() - $seconds) > (int) config('video.webhook_tolerance_seconds')) {
            return $this->reject('stale', $timestamp, $signature, $payload, $headers);
        }

        $expected = base64_encode(hash_hmac('sha256', $timestamp.'.'.$payload, $key, true));

        if (! hash_equals($expected, $signature)) {
            return $this->reject('mismatch', $timestamp, $signature, $payload, $headers, $secret);
        }

        return true;
    }

    /**
     * Logs one diagnostic line for a rejected webhook and returns `false`, so every `verifyWebhook`
     * exit but the success one flows through here (R166(b)). Never logs the secret, the body or the
     * signature value. For `mismatch` only, adds two booleans that narrow down what Daily's real
     * request differs on without ever putting the credential or the body in the log.
     *
     * @param  array<string, string>  $headers
     */
    private function reject(string $reason, string $timestamp, string $signature, string $payload, array $headers, ?string $rawSecret = null): false
    {
        $context = [
            'provider' => VideoProviderCode::Daily->value,
            'reason' => $reason,
            'timestamp' => $timestamp,
            'timestamp_digits' => strlen($timestamp),
            'signature_length' => strlen($signature),
            'body_length' => strlen($payload),
            'header_names' => array_keys($headers),
        ];

        if ($reason === 'mismatch' && $rawSecret !== null) {
            $context['matches_with_raw_secret'] = hash_equals(
                base64_encode(hash_hmac('sha256', $timestamp.'.'.$payload, $rawSecret, true)),
                $signature,
            );

            $normalisedBody = json_validate($payload) ? json_encode(json_decode($payload)) : false;

            $context['matches_with_normalised_body'] = $normalisedBody !== false
                && hash_equals(
                    base64_encode(hash_hmac('sha256', $timestamp.'.'.$normalisedBody, base64_decode($rawSecret, true) ?: '', true)),
                    $signature,
                );
        }

        Log::warning('video.webhook.rejected', $context);

        return false;
    }

    public function parseWebhook(string $payload): ?AttendanceEvent
    {
        return WebhookPayload::parse($payload);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('video.daily.base_url'))
            ->withToken($this->credentials['api_key'] ?? '')
            ->acceptJson()
            ->asJson()
            ->timeout(10);
    }

    /**
     * @throws VideoProviderException
     */
    private function ensureOk(Response $response, string $what): void
    {
        if ($response->failed()) {
            throw new VideoProviderException("Daily {$what} failed (HTTP {$response->status()}).");
        }
    }
}
