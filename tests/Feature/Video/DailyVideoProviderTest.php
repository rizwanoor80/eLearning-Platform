<?php

use App\Enums\VideoParticipant;
use App\Models\VideoProvider;
use App\Services\Video\DailyVideoProvider;
use App\Services\Video\VideoProviderException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const DAILY_TEST_KEY = 'k-secret-daily-key';

const DAILY_WEBHOOK_RAW_SECRET = 'daily-webhook-secret';

function daily(): DailyVideoProvider
{
    return new DailyVideoProvider(['api_key' => DAILY_TEST_KEY, 'webhook_secret' => base64_encode(DAILY_WEBHOOK_RAW_SECRET)]);
}

function dailySignature(string $timestamp, string $body, string $rawSecret = DAILY_WEBHOOK_RAW_SECRET): string
{
    return base64_encode(hash_hmac('sha256', $timestamp.'.'.$body, $rawSecret, true));
}

/**
 * @return array<MessageLogged>
 */
function captureLogs(callable $fn): array
{
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e;
    });

    $fn();

    return $logged;
}

beforeEach(fn () => Http::preventStrayRequests());

it('creates a private room named for the lesson', function () {
    Http::fake(['api.daily.co/v1/rooms' => Http::response(['name' => 'lesson-7', 'url' => 'https://x.daily.co/lesson-7'])]);

    $room = daily()->createRoom('lesson-7', now()->addHour());

    expect($room->name)->toBe('lesson-7')->and($room->url)->toBe('https://x.daily.co/lesson-7');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->hasHeader('Authorization', 'Bearer '.DAILY_TEST_KEY)
        && $r['name'] === 'lesson-7' && $r['privacy'] === 'private');
});

it('fetches the existing room when it already exists so a second create is a no-op', function () {
    Http::fake([
        'api.daily.co/v1/rooms/lesson-7' => Http::response(['name' => 'lesson-7', 'url' => 'https://x.daily.co/lesson-7']),
        'api.daily.co/v1/rooms' => Http::response(['error' => 'invalid-request-error', 'info' => 'a room named lesson-7 already exists'], 400),
    ]);

    expect(daily()->createRoom('lesson-7', now()->addHour())->url)->toBe('https://x.daily.co/lesson-7');
});

it('issues a per-participant token carrying the participant id', function () {
    Http::fake(['api.daily.co/v1/meeting-tokens' => Http::response(['token' => 'tok-abc'])]);

    expect(daily()->joinToken('lesson-7', VideoParticipant::Learner, 'Sam', now()->addHour()))->toBe('tok-abc');

    Http::assertSent(fn (Request $r) => $r['properties']['user_id'] === 'learner'
        && $r['properties']['room_name'] === 'lesson-7'
        && $r['properties']['is_owner'] === false);
});

it('tolerates a 404 when closing a room that is already gone', function () {
    Http::fake(['api.daily.co/v1/rooms/lesson-7' => Http::response([], 404)]);

    daily()->closeRoom('lesson-7');

    Http::assertSentCount(1);
});

it('never leaks the api key into an exception, a log line or the registry row', function () {
    Http::fake(['*' => Http::response(['error' => 'boom '.DAILY_TEST_KEY], 500)]);

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.print_r(array_map(fn ($v) => $v instanceof Throwable ? (string) $v : $v, $e->context), true);
    });

    $message = '';

    try {
        daily()->createRoom('lesson-7', now()->addHour());
    } catch (VideoProviderException $e) {
        report($e);
        $message = $e->getMessage().$e->getTraceAsString();
    }

    expect($message)->toContain('HTTP 500')->not->toContain(DAILY_TEST_KEY)
        ->and($logged)->not->toBe([])
        ->and(implode('', $logged))->toContain('HTTP 500')->not->toContain(DAILY_TEST_KEY);

    $row = VideoProvider::query()->where('code', 'daily')->firstOrFail();
    $row->credentials = ['api_key' => DAILY_TEST_KEY, 'webhook_secret' => 'x'];
    $row->save();

    expect(json_encode($row->fresh()->toArray()))->not->toContain(DAILY_TEST_KEY);
});

it('verifies a webhook signed with a seconds-form timestamp', function () {
    $body = '{"test":"test"}';
    $timestamp = (string) time();

    expect(daily()->verifyWebhook($body, [
        'x-webhook-timestamp' => $timestamp,
        'x-webhook-signature' => dailySignature($timestamp, $body),
    ]))->toBeTrue();
});

it('verifies a webhook signed with a millisecond-form timestamp', function () {
    $body = '{"test":"test"}';
    $timestamp = (string) (time() * 1000);

    expect(daily()->verifyWebhook($body, [
        'x-webhook-timestamp' => $timestamp,
        'x-webhook-signature' => dailySignature($timestamp, $body),
    ]))->toBeTrue();
});

it('rejects a stale millisecond-form timestamp and logs it as stale, not mismatch', function () {
    $body = '{"test":"test"}';
    $timestamp = (string) ((time() - 3600) * 1000);
    $signature = dailySignature($timestamp, $body);

    $logged = captureLogs(function () use ($body, $timestamp, $signature) {
        expect(daily()->verifyWebhook($body, [
            'x-webhook-timestamp' => $timestamp,
            'x-webhook-signature' => $signature,
        ]))->toBeFalse();
    });

    $context = collect($logged)->firstWhere('message', 'video.webhook.rejected')->context;

    expect($context['reason'])->toBe('stale')
        ->and($context)->not->toHaveKey('matches_with_raw_secret')
        ->and($context)->not->toHaveKey('matches_with_normalised_body');
});

it('still rejects a stale seconds-form timestamp and logs it as stale, not mismatch', function () {
    $body = '{"test":"test"}';
    $timestamp = (string) (time() - 3600);
    $signature = dailySignature($timestamp, $body);

    $logged = captureLogs(function () use ($body, $timestamp, $signature) {
        expect(daily()->verifyWebhook($body, [
            'x-webhook-timestamp' => $timestamp,
            'x-webhook-signature' => $signature,
        ]))->toBeFalse();
    });

    $context = collect($logged)->firstWhere('message', 'video.webhook.rejected')->context;

    expect($context['reason'])->toBe('stale')
        ->and($context)->not->toHaveKey('matches_with_raw_secret')
        ->and($context)->not->toHaveKey('matches_with_normalised_body');
});

it('logs exactly one warning with no credential, body or signature content on a mismatch', function () {
    $body = '{"test":"test"}';
    $timestamp = (string) time();
    $signature = dailySignature($timestamp, $body, 'wrong-secret');

    $logged = captureLogs(function () use ($body, $timestamp, $signature) {
        expect(daily()->verifyWebhook($body, [
            'x-webhook-timestamp' => $timestamp,
            'x-webhook-signature' => $signature,
        ]))->toBeFalse();
    });

    $rejections = array_values(array_filter($logged, fn (MessageLogged $e) => $e->message === 'video.webhook.rejected'));

    expect($rejections)->toHaveCount(1);

    $context = $rejections[0]->context;
    $encoded = json_encode($context);

    expect($context['reason'])->toBe('mismatch')
        ->and($context['provider'])->toBe('daily')
        ->and($context['timestamp'])->toBe($timestamp)
        ->and($context['timestamp_digits'])->toBe(strlen($timestamp))
        ->and($context['signature_length'])->toBe(strlen($signature))
        ->and($context['body_length'])->toBe(strlen($body))
        ->and($context['header_names'])->toBe(['x-webhook-timestamp', 'x-webhook-signature'])
        ->and($context['matches_with_raw_secret'])->toBeFalse()
        ->and($context['matches_with_normalised_body'])->toBeFalse()
        ->and($encoded)->not->toContain(DAILY_WEBHOOK_RAW_SECRET)
        ->not->toContain(base64_encode(DAILY_WEBHOOK_RAW_SECRET))
        ->not->toContain($body)
        ->not->toContain($signature);
});

it('flags a mismatch that would verify with the base64 secret used as a raw, un-decoded key', function () {
    $body = '{"test":"test"}';
    $timestamp = (string) time();
    // Signed with the base64-encoded secret itself as the HMAC key, not the decoded raw secret.
    $signature = dailySignature($timestamp, $body, base64_encode(DAILY_WEBHOOK_RAW_SECRET));

    $logged = captureLogs(function () use ($body, $timestamp, $signature) {
        expect(daily()->verifyWebhook($body, [
            'x-webhook-timestamp' => $timestamp,
            'x-webhook-signature' => $signature,
        ]))->toBeFalse();
    });

    $context = collect($logged)->firstWhere('message', 'video.webhook.rejected')->context;

    expect($context['matches_with_raw_secret'])->toBeTrue();
});

it('flags a mismatch that would verify over a JSON-normalised body', function () {
    $wire = '{ "test" : "test" }';
    $normalised = json_encode(json_decode($wire));
    $timestamp = (string) time();
    // Signed over the normalised body, but Daily's actual request carries the original wire form.
    $signature = dailySignature($timestamp, $normalised);

    $logged = captureLogs(function () use ($wire, $timestamp, $signature) {
        expect(daily()->verifyWebhook($wire, [
            'x-webhook-timestamp' => $timestamp,
            'x-webhook-signature' => $signature,
        ]))->toBeFalse();
    });

    $context = collect($logged)->firstWhere('message', 'video.webhook.rejected')->context;

    expect($context['matches_with_normalised_body'])->toBeTrue();
});

it('logs a no_key reason without the mismatch-only booleans when no secret is configured', function () {
    $provider = new DailyVideoProvider(['api_key' => DAILY_TEST_KEY, 'webhook_secret' => '']);
    $body = '{"test":"test"}';
    $timestamp = (string) time();

    $logged = captureLogs(function () use ($provider, $body, $timestamp) {
        expect($provider->verifyWebhook($body, [
            'x-webhook-timestamp' => $timestamp,
            'x-webhook-signature' => dailySignature($timestamp, $body),
        ]))->toBeFalse();
    });

    $context = collect($logged)->firstWhere('message', 'video.webhook.rejected')->context;

    expect($context['reason'])->toBe('no_key')
        ->and($context)->not->toHaveKey('matches_with_raw_secret')
        ->and($context)->not->toHaveKey('matches_with_normalised_body');
});

it('logs a bad_timestamp reason for a non-numeric timestamp header', function () {
    $body = '{"test":"test"}';

    $logged = captureLogs(function () use ($body) {
        expect(daily()->verifyWebhook($body, [
            'x-webhook-timestamp' => 'not-a-number',
            'x-webhook-signature' => dailySignature('not-a-number', $body),
        ]))->toBeFalse();
    });

    $context = collect($logged)->firstWhere('message', 'video.webhook.rejected')->context;

    expect($context['reason'])->toBe('bad_timestamp');
});
