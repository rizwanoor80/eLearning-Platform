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

function daily(): DailyVideoProvider
{
    return new DailyVideoProvider(['api_key' => DAILY_TEST_KEY, 'webhook_secret' => base64_encode('daily-webhook-secret')]);
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
