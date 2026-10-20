<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Payments\ApplyPaymentWebhook;
use App\Enums\PaymentGatewayCode;
use App\Enums\PaymentGatewayMode;
use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentWebhookEvent;
use App\Services\Payments\NoActivePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * `POST webhooks/payments/{code}` (23b, R247): payment gateway events. No session, no CSRF — the
 * signature is the authentication — and the same shape as `VideoWebhookController`: the row named in
 * the URL verifies the body, so an event for a payment made on a gateway that has since been
 * switched away from still verifies.
 *
 * Order matters: reject before storing. An unknown code, a code with no driver or no webhook secret
 * is 404, an oversized body 413, a bad or stale signature 401. A verified event is stored once
 * (unique on gateway + event id) and applied in the same transaction, so a failure rolls the row back
 * and the gateway's retry is not answered `duplicate`; a replay answers 200 and does nothing. Event
 * types we do not act on, and events from the other mode (a test event at a live endpoint or the
 * reverse), are acknowledged with 200 and not stored. The body, the headers and the signature are
 * never stored or logged.
 */
class PaymentWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 65536;

    public function __invoke(Request $request, string $code, PaymentGatewayManager $manager, ApplyPaymentWebhook $apply): JsonResponse
    {
        $gatewayCode = PaymentGatewayCode::tryFrom($code);
        $row = $gatewayCode === null ? null : PaymentGatewayAccount::query()->where('code', $gatewayCode->value)->first();

        if ($gatewayCode === null || $row === null || $gatewayCode === PaymentGatewayCode::Fake || ! $gatewayCode->hasDriver() || ! $row->hasCredential('webhook_secret')) {
            return response()->json(['status' => 'unknown_gateway'], 404);
        }

        $payload = $request->getContent();

        if (strlen($payload) > self::MAX_BODY_BYTES) {
            return response()->json(['status' => 'too_large'], 413);
        }

        try {
            $driver = $manager->forRow($row);
        } catch (NoActivePaymentGateway) {
            return response()->json(['status' => 'unknown_gateway'], 404);
        }

        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        if (! $driver->verifyWebhook($payload, $headers)) {
            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $event = json_decode($payload, true);
        $type = is_array($event) ? ($event['type'] ?? null) : null;
        $id = is_array($event) ? ($event['id'] ?? null) : null;
        $object = is_array($event) ? ($event['data']['object'] ?? null) : null;

        if (! is_string($type) || ! is_string($id) || $id === '' || strlen($id) > 255 || ! is_array($object)) {
            return response()->json(['status' => 'ignored']);
        }

        if (! in_array($type, ApplyPaymentWebhook::HANDLED, true) || ($event['livemode'] ?? null) !== ($row->mode === PaymentGatewayMode::Live)) {
            return response()->json(['status' => 'ignored']);
        }

        $reference = $object['payment_intent'] ?? $object['id'] ?? null;
        $created = $event['created'] ?? null;

        $status = DB::transaction(function () use ($row, $apply, $id, $type, $object, $reference, $created): string {
            $stored = PaymentWebhookEvent::query()->insertOrIgnore([
                'gateway_code' => $row->code,
                'event_id' => $id,
                'type' => $type,
                'object_ref' => is_string($reference) ? substr($reference, 0, 255) : null,
                'occurred_at' => is_int($created) ? CarbonImmutable::createFromTimestampUTC($created) : Date::now(),
                'received_at' => Date::now(),
            ]);

            if ($stored === 0) {
                return 'duplicate';
            }

            $apply($row->code, $type, $object);

            return 'received';
        });

        return response()->json(['status' => $status]);
    }
}
