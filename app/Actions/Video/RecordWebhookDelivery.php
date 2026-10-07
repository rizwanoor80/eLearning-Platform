<?php

namespace App\Actions\Video;

use App\Models\VideoWebhookDelivery;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * R180: leaves one metadata row per request to the video webhook endpoint, so the owner can see
 * whether a provider is calling and in what shape (ADR-017). Every value is derived from the decoded
 * body only — the headers, and with them the signature, are never passed in — and only names and
 * sizes are kept: the event type and id as sent (cleaned, length-capped) and the *names* of the
 * top-level and `payload` keys. A value, a body or a token cannot reach the row or the log line.
 *
 * The shape is kept only for a delivery whose signature verified. A rejected request (401, 404, 413)
 * is unauthenticated, so anyone can send it: it leaves status, outcome and body length alone, which
 * keeps what a stranger can make us store to a few dozen bytes per request.
 *
 * It must never change the response or stop the real event, so the insert runs in its own
 * transaction (a savepoint when an outer one exists, so a failure here cannot abort the caller's
 * transaction on PostgreSQL) and every failure is reported and swallowed.
 */
class RecordWebhookDelivery
{
    /** A body this large is not decoded at all: only its length is kept. */
    private const DECODE_LIMIT_BYTES = 65536;

    /** The outcomes that follow a verified signature, and so may carry the body's shape. */
    private const SHAPED_OUTCOMES = ['received', 'duplicate', 'ignored'];

    private const MAX_KEYS = 50;

    private const MAX_KEY_LENGTH = 64;

    private const MAX_TYPE_LENGTH = 64;

    private const MAX_ID_LENGTH = 128;

    public function __invoke(string $providerCode, string $body, int $httpStatus, string $outcome): void
    {
        try {
            $shape = in_array($outcome, self::SHAPED_OUTCOMES, true) ? $this->shape($body) : $this->shape('');

            DB::transaction(fn () => VideoWebhookDelivery::query()->create([
                'provider_code' => substr($providerCode, 0, 16),
                'received_at' => Date::now(),
                'http_status' => $httpStatus,
                'outcome' => substr($outcome, 0, 32),
                'event_type' => $shape['event_type'],
                'event_id' => $shape['event_id'],
                'body_length' => strlen($body),
                'top_level_keys' => $shape['top_level_keys'],
                'payload_keys' => $shape['payload_keys'],
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The `video.webhook.ignored` line: a delivery that verified but was not an attendance event.
     * The same safe fields as the row and nothing else.
     */
    public function logIgnored(string $providerCode, string $body): void
    {
        try {
            Log::info('video.webhook.ignored', ['provider' => substr($providerCode, 0, 16), 'body_length' => strlen($body)] + $this->shape($body));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array{event_type: ?string, event_id: ?string, top_level_keys: ?list<string>, payload_keys: ?list<string>}
     */
    public function shape(string $body): array
    {
        $shape = ['event_type' => null, 'event_id' => null, 'top_level_keys' => null, 'payload_keys' => null];

        if (strlen($body) > self::DECODE_LIMIT_BYTES) {
            return $shape;
        }

        $decoded = json_decode($body, true, 8);

        if (! is_array($decoded) || array_is_list($decoded)) {
            return $shape;
        }

        $shape['event_type'] = is_string($decoded['type'] ?? null) ? $this->clean($decoded['type'], self::MAX_TYPE_LENGTH) : null;
        $shape['event_id'] = is_string($decoded['id'] ?? null) ? $this->clean($decoded['id'], self::MAX_ID_LENGTH) : null;
        $shape['top_level_keys'] = $this->keyNames($decoded);
        $shape['payload_keys'] = is_array($decoded['payload'] ?? null) && ! array_is_list($decoded['payload']) ? $this->keyNames($decoded['payload']) : null;

        return $shape;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function keyNames(array $values): array
    {
        return array_map(
            fn (int|string $key): string => $this->clean((string) $key, self::MAX_KEY_LENGTH),
            array_slice(array_keys($values), 0, self::MAX_KEYS),
        );
    }

    /**
     * Anything outside a plain identifier alphabet becomes `?`: the sender controls these strings and
     * the `video:webhooks` command prints them, so no control or escape character may survive.
     */
    private function clean(string $value, int $maxLength): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_.:\-]/', '?', $value), 0, $maxLength);
    }
}
