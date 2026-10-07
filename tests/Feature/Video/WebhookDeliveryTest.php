<?php

use App\Enums\VideoEventType;
use App\Enums\VideoParticipant;
use App\Events\Video\VideoWebhookReceived;
use App\Models\VideoProvider;
use App\Models\VideoWebhookDelivery;
use App\Models\VideoWebhookEvent;
use App\Services\Video\DailyVideoProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

// R180: every request to the video webhook endpoint leaves a metadata row, and `video:webhooks`
// reads them back. Nothing in the row, the log or the command output may be a body, header or secret.

const DELIVERY_SENTINEL_NAME = 'SENTINEL-NOT-A-REAL-NAME';

const DELIVERY_DAILY_SECRET = 'delivery-test-daily-secret';

beforeEach(function () {
    $fake = VideoProvider::query()->where('code', 'fake')->firstOrFail();
    $fake->credentials = ['webhook_secret' => FAKE_WEBHOOK_SECRET];
    $fake->save();
});

afterEach(function () {
    VideoWebhookDelivery::flushEventListeners();
});

function dailyFixture(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/daily/participant-joined.json'));
}

function dailySignedHeaders(string $body): array
{
    $timestamp = (string) time();

    return [
        'X-Webhook-Timestamp' => $timestamp,
        'X-Webhook-Signature' => base64_encode(hash_hmac('sha256', $timestamp.'.'.$body, DELIVERY_DAILY_SECRET, true)),
    ];
}

function useDailyCredentials(): void
{
    $row = VideoProvider::query()->where('code', 'daily')->firstOrFail();
    $row->credentials = ['api_key' => 'test-daily-api-key', 'webhook_secret' => base64_encode(DELIVERY_DAILY_SECRET)];
    $row->save();
}

/**
 * @return array<int, array<string, mixed>>
 */
function loggedContexts(callable $fn): array
{
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = ['message' => $e->message, 'context' => $e->context];
    });
    $fn();

    return $logged;
}

// ---- Daily's documented body ---------------------------------------------------------------

it('parses the documented Daily participant.joined body, fractional timestamp and extra keys included', function () {
    $event = (new DailyVideoProvider(['api_key' => 'x', 'webhook_secret' => base64_encode(DELIVERY_DAILY_SECRET)]))->parseWebhook(dailyFixture());

    expect($event)->not->toBeNull()
        ->and($event->id)->toBe('ptcpt-joined-6497c79b-f326-4942-aef8-c36a29140ad1-1708972279961')
        ->and($event->type)->toBe(VideoEventType::Joined)
        ->and($event->roomName)->toBe('lesson-42')
        ->and($event->participant)->toBe(VideoParticipant::Tutor)
        ->and($event->occurredAt->getTimestamp())->toBe(1708972279)
        ->and($event->lessonId())->toBe(42);
});

it('accepts the documented Daily body end to end, signed the way Daily signs it', function () {
    Event::fake([VideoWebhookReceived::class]);
    useDailyCredentials();
    $body = dailyFixture();

    postWebhook('daily', $body, dailySignedHeaders($body))->assertOk()->assertJson(['status' => 'received']);

    expect(VideoWebhookEvent::query()->sole()->room_name)->toBe('lesson-42');

    $delivery = VideoWebhookDelivery::query()->sole();

    expect($delivery->provider_code)->toBe('daily')
        ->and($delivery->http_status)->toBe(200)
        ->and($delivery->outcome)->toBe('received')
        ->and($delivery->event_type)->toBe('participant.joined')
        ->and($delivery->top_level_keys)->toBe(['version', 'type', 'id', 'payload', 'event_ts'])
        ->and($delivery->payload_keys)->toContain('room', 'user_id', 'session_id', 'joined_at', 'networkQualityState', 'permissions');
});

it('records a signed Daily verification ping that is not an attendance event as ignored, and logs it', function () {
    useDailyCredentials();
    $body = '{"test":"test"}';

    $logged = loggedContexts(fn () => postWebhook('daily', $body, dailySignedHeaders($body))->assertOk()->assertJson(['status' => 'ignored']));

    $delivery = VideoWebhookDelivery::query()->sole();

    expect($delivery->http_status)->toBe(200)
        ->and($delivery->outcome)->toBe('ignored')
        ->and($delivery->top_level_keys)->toBe(['test'])
        ->and($delivery->payload_keys)->toBeNull()
        ->and(VideoWebhookEvent::query()->count())->toBe(0);

    $line = collect($logged)->firstWhere('message', 'video.webhook.ignored');

    expect($line)->not->toBeNull()
        ->and($line['context'])->toMatchArray(['provider' => 'daily', 'body_length' => strlen($body), 'top_level_keys' => ['test']]);
});

// ---- One row per request, on every path ----------------------------------------------------

it('records a row for a received event, a replay, an ignored event, a bad signature, an unknown provider and an oversized body', function () {
    Event::fake([VideoWebhookReceived::class]);
    $body = webhookBody();

    postWebhook('fake', $body, signedHeaders($body))->assertJson(['status' => 'received']);
    postWebhook('fake', $body, signedHeaders($body))->assertJson(['status' => 'duplicate']);

    $ignored = webhookBody('evt-2', ['type' => 'meeting.started']);
    postWebhook('fake', $ignored, signedHeaders($ignored))->assertJson(['status' => 'ignored']);

    postWebhook('fake', $body, signedHeaders($body, null, 'some-other-secret'))->assertStatus(401);
    postWebhook('nope', $body, [])->assertStatus(404);

    $huge = json_encode(['id' => 'big', 'type' => 'participant.joined', 'payload' => ['room' => str_repeat('x', 70000)]]);
    postWebhook('fake', $huge, signedHeaders($huge))->assertStatus(413);

    $rows = VideoWebhookDelivery::query()->orderBy('id')->get();

    expect($rows->map(fn ($row) => [$row->provider_code, $row->http_status, $row->outcome])->all())->toBe([
        ['fake', 200, 'received'],
        ['fake', 200, 'duplicate'],
        ['fake', 200, 'ignored'],
        ['fake', 401, 'invalid_signature'],
        ['nope', 404, 'unknown_provider'],
        ['fake', 413, 'too_large'],
    ]);

    $first = $rows->first();
    $big = $rows->last();

    expect($first->event_type)->toBe('participant.joined')
        ->and($first->event_id)->toBe('evt-1')
        ->and($first->body_length)->toBe(strlen($body))
        ->and($first->top_level_keys)->toBe(['id', 'type', 'event_ts', 'payload'])
        ->and($first->payload_keys)->toBe(['room', 'user_id'])
        ->and($big->body_length)->toBe(strlen($huge))
        ->and([$big->event_type, $big->event_id, $big->top_level_keys, $big->payload_keys])->toBe([null, null, null, null]);
});

it('records unparseable and non-object bodies by length alone', function () {
    postWebhook('fake', 'not json at all', [])->assertStatus(401);
    postWebhook('fake', '[1,2,3]', [])->assertStatus(401);

    $rows = VideoWebhookDelivery::query()->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('body_length')->all())->toBe([15, 7])
        ->and($rows->pluck('top_level_keys')->all())->toBe([null, null]);
});

it('cleans and caps what the sender controls', function () {
    $body = json_encode([
        'id' => "evt\x1b[31m-".str_repeat('a', 300),
        'type' => str_repeat('t', 200),
        ...array_combine(array_map(fn ($i) => "key-{$i}-\x07", range(1, 60)), array_fill(0, 60, 1)),
    ]);

    postWebhook('fake', $body, [])->assertStatus(401);

    $row = VideoWebhookDelivery::query()->sole();

    expect(strlen($row->event_id))->toBe(128)
        ->and($row->event_id)->not->toContain("\x1b")
        ->and(strlen($row->event_type))->toBe(64)
        ->and($row->top_level_keys)->toHaveCount(50)
        ->and(implode('', $row->top_level_keys))->not->toContain("\x07");
});

// ---- Nothing sensitive reaches the row, the log or the command -----------------------------

it('lets no body value, header or signature reach the row, the log or the command output', function () {
    $body = webhookBody('evt-s', ['type' => 'meeting.started', 'payload' => ['user_name' => DELIVERY_SENTINEL_NAME]]);
    $headers = signedHeaders($body) + ['X-Sentinel-Token' => 'SENTINEL-TOKEN-VALUE'];
    $signature = $headers['X-Webhook-Signature'];

    $logged = loggedContexts(fn () => postWebhook('fake', $body, $headers)->assertJson(['status' => 'ignored']));

    expect(Artisan::call('video:webhooks'))->toBe(0);

    $output = Artisan::output();
    $row = json_encode(VideoWebhookDelivery::query()->sole()->getAttributes());

    foreach ([$row, json_encode($logged), $output] as $surface) {
        expect($surface)
            ->not->toContain(DELIVERY_SENTINEL_NAME)
            ->not->toContain('SENTINEL-TOKEN-VALUE')
            ->not->toContain($signature)
            ->not->toContain(FAKE_WEBHOOK_SECRET)
            ->not->toContain('lesson-42');
    }

    expect($row)->toContain('user_name');
});

// ---- The delivery write never blocks the real event ---------------------------------------

it('still stores and dispatches the event when the delivery insert fails with a database error', function () {
    Event::fake([VideoWebhookReceived::class]);
    Exceptions::fake();
    // Out of range for the smallint column: a real PostgreSQL error, which would abort the enclosing
    // transaction (the test's, or a request's) if the insert were not in its own savepoint.
    VideoWebhookDelivery::creating(function (VideoWebhookDelivery $delivery): void {
        $delivery->http_status = 99999;
    });
    $body = webhookBody();

    postWebhook('fake', $body, signedHeaders($body))->assertOk()->assertJson(['status' => 'received']);

    expect(VideoWebhookEvent::query()->count())->toBe(1)
        ->and(VideoWebhookDelivery::query()->count())->toBe(0);
    Event::assertDispatchedTimes(VideoWebhookReceived::class, 1);
    Exceptions::assertReported(QueryException::class);
});

it('keeps the response a 401 and stores nothing when the delivery insert fails on a rejection', function () {
    Exceptions::fake();
    VideoWebhookDelivery::creating(function (): void {
        throw new RuntimeException('delivery table unavailable');
    });
    $body = webhookBody();

    postWebhook('fake', $body, signedHeaders($body, null, 'some-other-secret'))->assertStatus(401);

    expect(VideoWebhookEvent::query()->count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);
});

it('records a 500 when the event itself fails, and leaves no event row behind', function () {
    Event::listen(VideoWebhookReceived::class, function (): void {
        throw new RuntimeException('queue outage');
    });
    $body = webhookBody();

    postWebhook('fake', $body, signedHeaders($body))->assertStatus(500);

    $delivery = VideoWebhookDelivery::query()->sole();

    expect($delivery->http_status)->toBe(500)
        ->and($delivery->outcome)->toBe('error')
        ->and(VideoWebhookEvent::query()->count())->toBe(0);
});

// ---- video:webhooks -------------------------------------------------------------------------

it('lists recent deliveries with a summary and honours --since', function () {
    VideoWebhookDelivery::factory()->create(['event_id' => 'recent-1', 'received_at' => now()->subHours(2)]);
    VideoWebhookDelivery::factory()->create(['event_id' => 'recent-2', 'http_status' => 401, 'outcome' => 'invalid_signature', 'received_at' => now()->subMinutes(10)]);
    VideoWebhookDelivery::factory()->create(['event_id' => 'old-1', 'received_at' => now()->subDays(2)]);

    $this->artisan('video:webhooks')
        ->expectsOutputToContain('2 deliveries since')
        ->expectsOutputToContain('recent-1')
        ->expectsOutputToContain('invalid_signature')
        ->doesntExpectOutputToContain('old-1')
        ->assertSuccessful();

    $this->artisan('video:webhooks --since=3d')->expectsOutputToContain('3 deliveries since')->expectsOutputToContain('old-1')->assertSuccessful();
    $this->artisan('video:webhooks --since=30m')->expectsOutputToContain('1 deliveries since')->doesntExpectOutputToContain('recent-1')->assertSuccessful();
});

it('says so plainly when nothing has arrived', function () {
    $this->artisan('video:webhooks')->expectsOutputToContain('0 deliveries since')->assertSuccessful();
});

it('refuses a malformed --since', function (string $value) {
    $this->artisan("video:webhooks --since={$value}")->expectsOutputToContain('--since must be')->assertFailed();
})->with(['24', 'abc', '0h', '-5h', '12345h']);

// ---- Pruning --------------------------------------------------------------------------------

it('prunes deliveries older than 30 days and nothing else, and is safe to run twice', function () {
    $old = VideoWebhookDelivery::factory()->create(['received_at' => now()->subDays(31)]);
    $kept = VideoWebhookDelivery::factory()->create(['received_at' => now()->subDays(29)]);
    $event = VideoWebhookEvent::query()->insertGetId([
        'provider_code' => 'fake', 'event_id' => 'e-old', 'type' => 'joined', 'room_name' => 'lesson-1',
        'participant' => 'tutor', 'occurred_at' => now()->subDays(60), 'received_at' => now()->subDays(60),
    ]);

    $this->artisan('video:prune-webhook-deliveries')->expectsOutputToContain('Pruned 1 webhook deliveries')->assertSuccessful();
    $this->artisan('video:prune-webhook-deliveries')->expectsOutputToContain('Pruned 0 webhook deliveries')->assertSuccessful();

    expect(VideoWebhookDelivery::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(VideoWebhookDelivery::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(DB::table('video_webhook_events')->where('id', $event)->exists())->toBeTrue();
});

it('prunes in chunks past the first thousand', function () {
    DB::table('video_webhook_deliveries')->insert(array_fill(0, 1005, ['provider_code' => 'daily', 'received_at' => now()->subDays(40), 'body_length' => 10]));

    $this->artisan('video:prune-webhook-deliveries')->expectsOutputToContain('Pruned 1005')->assertSuccessful();

    expect(VideoWebhookDelivery::query()->count())->toBe(0);
});

it('schedules the prune daily on one server without overlap', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'video:prune-webhook-deliveries'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});
