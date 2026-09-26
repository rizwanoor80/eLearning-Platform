<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\VideoProviderCode;
use App\Events\Video\VideoWebhookReceived;
use App\Http\Controllers\Controller;
use App\Models\VideoProvider;
use App\Models\VideoWebhookEvent;
use App\Services\Video\VideoProviderException;
use App\Services\Video\VideoProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * `POST webhooks/video/{code}`: provider attendance webhooks. No session, no CSRF (the signature is
 * the authentication). The row named in the URL verifies the body, not the active row, so a
 * webhook for a lesson made on a provider that has since been switched away from still verifies.
 *
 * The fake provider has no endpoint in production (R125). Rooms of every provider are named
 * `lesson-<id>`, so the listener (7c) must also apply an event only when the lesson's
 * `room_provider` equals the event's provider code.
 *
 * Order matters: reject before storing. An unknown code or a row without credentials is 404, a bad
 * or stale signature is 401, a verified event is stored once (unique on provider + event id) and
 * only then dispatched, both inside one transaction — a replay answers 200 and does nothing.
 */
class VideoWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 65536;

    public function __invoke(Request $request, string $code, VideoProviderManager $manager): JsonResponse
    {
        $providerCode = VideoProviderCode::tryFrom($code);
        $row = $providerCode === null ? null : VideoProvider::query()->where('code', $providerCode->value)->first();

        if ($providerCode === null || $row === null || ! $providerCode->hasDriver() || ! $row->hasCredential('webhook_secret') || ($providerCode === VideoProviderCode::Fake && app()->environment('production'))) {
            return response()->json(['status' => 'unknown_provider'], 404);
        }

        $payload = $request->getContent();

        if (strlen($payload) > self::MAX_BODY_BYTES) {
            return response()->json(['status' => 'too_large'], 413);
        }

        try {
            $driver = $manager->forRow($row);
        } catch (VideoProviderException) {
            return response()->json(['status' => 'unknown_provider'], 404);
        }

        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        if (! $driver->verifyWebhook($payload, $headers)) {
            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $attendance = $driver->parseWebhook($payload);

        if ($attendance === null) {
            return response()->json(['status' => 'ignored']);
        }

        // Store and dispatch in one transaction: if the dispatch throws (a queue outage), the row is
        // rolled back and the 500 makes the provider retry, so a stored event is never lost undelivered.
        // Listeners of this event must therefore not be `afterCommit`.
        $status = DB::transaction(function () use ($row, $attendance): string {
            $stored = VideoWebhookEvent::query()->insertOrIgnore([
                'provider_code' => $row->code,
                'event_id' => $attendance->id,
                'type' => $attendance->type->value,
                'room_name' => $attendance->roomName,
                'participant' => $attendance->participant?->value,
                'occurred_at' => $attendance->occurredAt,
                'received_at' => Date::now(),
            ]);

            if ($stored === 0) {
                return 'duplicate';
            }

            VideoWebhookReceived::dispatch(
                VideoWebhookEvent::query()->where('provider_code', $row->code)->where('event_id', $attendance->id)->firstOrFail(),
            );

            return 'received';
        });

        return response()->json(['status' => $status]);
    }
}
