<?php

use App\Actions\Video\ActivateVideoProvider;
use App\Events\Video\VideoWebhookReceived;
use App\Models\VideoProvider;
use App\Models\VideoWebhookEvent;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Event;

const FAKE_WEBHOOK_SECRET = 'test-fake-webhook-secret';

beforeEach(function () {
    $fake = VideoProvider::query()->where('code', 'fake')->firstOrFail();
    $fake->credentials = ['webhook_secret' => FAKE_WEBHOOK_SECRET];
    $fake->save();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function webhookBody(string $id = 'evt-1', array $overrides = []): string
{
    return json_encode(array_replace_recursive([
        'id' => $id,
        'type' => 'participant.joined',
        'event_ts' => time(),
        'payload' => ['room' => 'lesson-42', 'user_id' => 'tutor'],
    ], $overrides));
}

/**
 * @return array<string, string>
 */
function signedHeaders(string $body, ?int $timestamp = null, string $secret = FAKE_WEBHOOK_SECRET): array
{
    $timestamp ??= time();

    return [
        'X-Webhook-Timestamp' => (string) $timestamp,
        'X-Webhook-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ];
}

function postWebhook(string $code, string $body, array $headers)
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', "/webhooks/video/{$code}", [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);
}

it('stores a correctly signed event once and dispatches it once', function () {
    Event::fake([VideoWebhookReceived::class]);
    $body = webhookBody();

    postWebhook('fake', $body, signedHeaders($body))->assertOk()->assertJson(['status' => 'received']);

    $event = VideoWebhookEvent::query()->sole();

    expect($event->room_name)->toBe('lesson-42')
        ->and($event->participant)->toBe('tutor')
        ->and($event->type)->toBe('joined');

    Event::assertDispatchedTimes(VideoWebhookReceived::class, 1);
});

it('treats a replay as a no-op that neither stores nor dispatches again', function () {
    Event::fake([VideoWebhookReceived::class]);
    $body = webhookBody();

    postWebhook('fake', $body, signedHeaders($body))->assertJson(['status' => 'received']);
    postWebhook('fake', $body, signedHeaders($body))->assertOk()->assertJson(['status' => 'duplicate']);

    expect(VideoWebhookEvent::query()->count())->toBe(1);
    Event::assertDispatchedTimes(VideoWebhookReceived::class, 1);
});

it('rejects a wrong signature without storing anything', function () {
    Event::fake([VideoWebhookReceived::class]);
    $body = webhookBody();

    postWebhook('fake', $body, signedHeaders($body, null, 'some-other-secret'))->assertStatus(401);

    expect(VideoWebhookEvent::query()->count())->toBe(0);
    Event::assertNotDispatched(VideoWebhookReceived::class);
});

it('rejects a tampered body', function () {
    $body = webhookBody();
    $headers = signedHeaders($body);

    postWebhook('fake', webhookBody('evt-1', ['payload' => ['user_id' => 'learner']]), $headers)->assertStatus(401);

    expect(VideoWebhookEvent::query()->count())->toBe(0);
});

it('rejects a missing signature and a stale timestamp', function () {
    $body = webhookBody();

    postWebhook('fake', $body, [])->assertStatus(401);
    postWebhook('fake', $body, signedHeaders($body, time() - 3600))->assertStatus(401);
    postWebhook('fake', $body, signedHeaders($body, time() + 3600))->assertStatus(401);

    expect(VideoWebhookEvent::query()->count())->toBe(0);
});

it('answers 404 for an unknown code, a code with no driver and a row without a secret', function () {
    $body = webhookBody();

    postWebhook('nonsense', $body, signedHeaders($body))->assertNotFound();
    postWebhook('zoom', $body, signedHeaders($body))->assertNotFound();
    postWebhook('daily', $body, signedHeaders($body))->assertNotFound();
});

it('ignores a verified event of a kind it does not use', function () {
    Event::fake([VideoWebhookReceived::class]);
    $body = webhookBody('evt-9', ['type' => 'recording.started']);

    postWebhook('fake', $body, signedHeaders($body))->assertOk()->assertJson(['status' => 'ignored']);

    expect(VideoWebhookEvent::query()->count())->toBe(0);
    Event::assertNotDispatched(VideoWebhookReceived::class);
});

it('refuses an oversized body', function () {
    $body = str_repeat('a', 70000);

    postWebhook('fake', $body, signedHeaders($body))->assertStatus(413);
});

it('is exempt from request-forgery verification for webhooks/* only', function () {
    // Laravel skips the forgery check while unit tests run, so a POST cannot prove the exemption;
    // assert the configured exclusion itself.
    $excluded = app(PreventRequestForgery::class)->getExcludedPaths();

    expect($excluded)->toContain('webhooks/*')
        ->and($excluded)->not->toContain('*')
        ->and($excluded)->not->toContain('login');
});

it('answers a signed post with no session', function () {
    $body = webhookBody();

    postWebhook('fake', $body, signedHeaders($body))->assertOk();
});

it('verifies a daily event with the base64 secret scheme even when daily is not the active provider', function () {
    $daily = VideoProvider::query()->where('code', 'daily')->firstOrFail();
    $secret = 'daily-webhook-secret';
    $daily->credentials = ['api_key' => 'k', 'webhook_secret' => base64_encode($secret)];
    $daily->save();

    expect($daily->is_active)->toBeFalse();

    $body = webhookBody('evt-daily', ['payload' => ['user_id' => 'learner']]);
    $timestamp = time();
    $signature = base64_encode(hash_hmac('sha256', $timestamp.'.'.$body, $secret, true));
    $headers = ['X-Webhook-Timestamp' => (string) $timestamp, 'X-Webhook-Signature' => $signature];

    postWebhook('daily', $body, $headers)->assertOk()->assertJson(['status' => 'received']);

    expect(VideoWebhookEvent::query()->where('provider_code', 'daily')->sole()->participant)->toBe('learner');

    $bad = ['X-Webhook-Timestamp' => (string) $timestamp, 'X-Webhook-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret)];
    postWebhook('daily', webhookBody('evt-other'), $bad)->assertStatus(401);
});

it('still verifies the fake provider after another provider becomes active', function () {
    $daily = VideoProvider::query()->where('code', 'daily')->firstOrFail();
    $daily->credentials = ['api_key' => 'k', 'webhook_secret' => base64_encode('s')];
    $daily->save();
    app(ActivateVideoProvider::class)($daily, null);

    $body = webhookBody('evt-after-switch');

    postWebhook('fake', $body, signedHeaders($body))->assertOk()->assertJson(['status' => 'received']);
});

it('throttles the endpoint per address', function () {
    expect(app('router')->getRoutes()->getByName('webhooks.video')->gatherMiddleware())->toContain('throttle:video-webhooks');
});

it('ignores a verified event that carries no timestamp instead of storing it at the epoch', function () {
    $payload = json_decode(webhookBody('evt-nots'), true);
    unset($payload['event_ts']);
    $body = json_encode($payload);

    postWebhook('fake', $body, signedHeaders($body))->assertOk()->assertJson(['status' => 'ignored']);

    expect(VideoWebhookEvent::query()->count())->toBe(0);
});

it('has no fake endpoint in production even when the fake row holds a secret', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        $body = webhookBody();

        postWebhook('fake', $body, signedHeaders($body))->assertNotFound();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    expect(VideoWebhookEvent::query()->count())->toBe(0);
});
