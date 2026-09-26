<?php

use App\Actions\Video\ActivateVideoProvider;
use App\Actions\Video\CloseLessonRoom;
use App\Actions\Video\CreateLessonRoom;
use App\Actions\Video\DeactivateVideoProvider;
use App\Enums\CurriculumCode;
use App\Enums\LessonStatus;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Models\VideoProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

afterEach(fn () => Carbon::setTestNow());

/**
 * A confirmed lesson starting `$startsInMinutes` from now (one hour long), for the room jobs.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function rmLesson(int $startsInMinutes = 10, array $overrides = []): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);

    $lesson = Lesson::factory()->create(array_merge([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addMinutes($startsInMinutes),
        'ends_at' => now()->addMinutes($startsInMinutes + 60),
    ], $overrides));

    return ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent];
}

function rmUseDaily(): void
{
    $daily = VideoProvider::query()->where('code', 'daily')->firstOrFail();
    $daily->credentials = ['api_key' => 'k-room-test', 'webhook_secret' => base64_encode('s')];
    $daily->save();
    app(ActivateVideoProvider::class)($daily, null);
}

function rmUseFake(): void
{
    app(ActivateVideoProvider::class)(VideoProvider::query()->where('code', 'fake')->firstOrFail(), null);
}

/**
 * Daily over a faked HTTP client. Flip `$state['down']` to make room creation and closing fail with a 500
 * (stubs registered first win in Http::fake, so re-faking cannot change an earlier answer).
 *
 * @param  ArrayObject<string, bool>  $state
 */
function rmFakeDaily(?ArrayObject $state = null): ArrayObject
{
    $state ??= new ArrayObject(['down' => false]);

    Http::preventStrayRequests();
    Http::fake([
        'api.daily.co/v1/rooms/*' => fn () => $state['down'] ? Http::response(['error' => 'down'], 500) : Http::response([]),
        'api.daily.co/v1/rooms' => fn (Request $r) => $state['down']
            ? Http::response(['error' => 'down'], 500)
            : Http::response(['name' => $r['name'], 'url' => 'https://x.daily.co/'.$r['name']]),
        'api.daily.co/v1/meeting-tokens' => Http::response(['token' => 'tok-1']),
    ]);

    return $state;
}

it('creates the room at T-15 min for a confirmed lesson and records the provider that made it', function () {
    ['lesson' => $due] = rmLesson(15);
    ['lesson' => $early] = rmLesson(16);

    $this->artisan('lessons:create-rooms')->assertSuccessful();

    $due->refresh();
    expect($due->room_provider)->toBe('fake')
        ->and($due->room_id)->toBe('lesson-'.$due->id)
        ->and($due->room_created_at)->not->toBeNull()
        ->and($due->tutor_join_url)->toBeNull()
        ->and($due->learner_join_url)->toBeNull()
        ->and($early->fresh()->room_id)->toBeNull();
});

it('leaves lessons that are not confirmed, or already over, without a room', function () {
    ['lesson' => $pending] = rmLesson(5, ['status' => LessonStatus::PendingPayment]);
    ['lesson' => $cancelled] = rmLesson(5, ['status' => LessonStatus::CancelledByParent]);
    ['lesson' => $over] = rmLesson(-90);

    $this->artisan('lessons:create-rooms')->assertSuccessful();

    expect($pending->fresh()->room_id)->toBeNull()
        ->and($cancelled->fresh()->room_id)->toBeNull()
        ->and($over->fresh()->room_id)->toBeNull();
});

it('creates exactly one room when the job runs twice: same room, one provider request', function () {
    rmUseDaily();
    rmFakeDaily();
    ['lesson' => $lesson] = rmLesson(10);

    $this->artisan('lessons:create-rooms')->assertSuccessful();
    $first = $lesson->fresh();
    $this->artisan('lessons:create-rooms')->assertSuccessful();

    expect($lesson->fresh()->room_id)->toBe($first->room_id)
        ->and($lesson->fresh()->room_created_at->toIso8601String())->toBe($first->room_created_at->toIso8601String())
        ->and($first->room_provider)->toBe('daily');
    Http::assertSentCount(1);
});

it('records one room when two runs race with the same stale copy of the lesson', function () {
    rmUseDaily();
    rmFakeDaily();
    ['lesson' => $lesson] = rmLesson(10);
    $copyA = Lesson::query()->findOrFail($lesson->id);
    $copyB = Lesson::query()->findOrFail($lesson->id);

    $a = app(CreateLessonRoom::class)($copyA);
    $b = app(CreateLessonRoom::class)($copyB);

    // Both called the provider (idempotent by room name); only one recorded it.
    expect([$a, $b])->toBe([true, false])
        ->and($lesson->fresh()->room_id)->toBe('lesson-'.$lesson->id);
});

it('does not create a room without an active provider, reports it and carries on', function () {
    app(DeactivateVideoProvider::class)(VideoProvider::query()->active()->firstOrFail(), null);
    ['lesson' => $lesson] = rmLesson(10);

    $this->artisan('lessons:create-rooms')->assertSuccessful();

    expect($lesson->fresh()->room_id)->toBeNull();

    // Once a provider is active again, the next run makes the room.
    rmUseFake();
    $this->artisan('lessons:create-rooms')->assertSuccessful();

    expect($lesson->fresh()->room_id)->not->toBeNull();
});

it('keeps trying the next minute when the provider fails, without blocking other lessons', function () {
    rmUseDaily();
    $state = rmFakeDaily(new ArrayObject(['down' => true]));
    ['lesson' => $lesson] = rmLesson(10);

    $this->artisan('lessons:create-rooms')->assertSuccessful();
    expect($lesson->fresh()->room_id)->toBeNull();

    $state['down'] = false;
    $this->artisan('lessons:create-rooms')->assertSuccessful();
    expect($lesson->fresh()->room_id)->not->toBeNull();
});

it('closes the room at the scheduled end plus ten minutes, once', function () {
    rmUseDaily();
    rmFakeDaily();
    ['lesson' => $lesson] = rmLesson(10);
    $this->artisan('lessons:create-rooms');

    Carbon::setTestNow($lesson->ends_at->copy()->addMinutes(9));
    $this->artisan('lessons:close-rooms')->assertSuccessful();
    expect($lesson->fresh()->room_closed_at)->toBeNull();

    Carbon::setTestNow($lesson->ends_at->copy()->addMinutes(10));
    $this->artisan('lessons:close-rooms')->assertSuccessful();
    expect($lesson->fresh()->room_closed_at)->not->toBeNull();

    $closedAt = $lesson->fresh()->room_closed_at;
    Carbon::setTestNow($lesson->ends_at->copy()->addMinutes(20));
    $this->artisan('lessons:close-rooms')->assertSuccessful();

    expect($lesson->fresh()->room_closed_at->toIso8601String())->toBe($closedAt->toIso8601String());
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/rooms/lesson-'.$lesson->id));
    Http::assertSentCount(2); // one create, one delete
});

it('closes a room through the provider that made it after the active provider is switched', function () {
    rmUseDaily();
    rmFakeDaily();
    ['lesson' => $lesson] = rmLesson(10);
    $this->artisan('lessons:create-rooms');
    expect($lesson->fresh()->room_provider)->toBe('daily');

    rmUseFake(); // the admin switches away from Daily while the room exists

    Carbon::setTestNow($lesson->ends_at->copy()->addMinutes(11));
    $this->artisan('lessons:close-rooms')->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'api.daily.co'));
    expect($lesson->fresh()->room_closed_at)->not->toBeNull();
});

it('does not mark the room closed when the provider fails, so the next run retries', function () {
    rmUseDaily();
    $state = rmFakeDaily();
    ['lesson' => $lesson] = rmLesson(10);
    $this->artisan('lessons:create-rooms');

    $state['down'] = true;
    Carbon::setTestNow($lesson->ends_at->copy()->addMinutes(11));
    $this->artisan('lessons:close-rooms')->assertSuccessful();
    expect($lesson->fresh()->room_closed_at)->toBeNull();

    $state['down'] = false;
    $this->artisan('lessons:close-rooms')->assertSuccessful();
    expect($lesson->fresh()->room_closed_at)->not->toBeNull();
});

it('closes the room of a lesson that was cancelled after its room was made', function () {
    ['lesson' => $lesson] = rmLesson(10);
    $this->artisan('lessons:create-rooms');
    Lesson::query()->whereKey($lesson->id)->update(['status' => LessonStatus::CancelledByParent, 'cancelled_at' => now()]);

    Carbon::setTestNow($lesson->ends_at->copy()->addMinutes(10));
    expect(app(CloseLessonRoom::class)($lesson->fresh()))->toBeTrue();
});

it('schedules the three lifecycle commands every minute', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('lessons:create-rooms')
        ->expectsOutputToContain('lessons:close-rooms')
        ->expectsOutputToContain('lessons:settle-ended')
        ->assertSuccessful();
});

// ---- join tokens ------------------------------------------------------------------------------------

it('issues a token to each side from T-10 min and stores nothing', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = rmLesson(10);
    $this->artisan('lessons:create-rooms');

    $this->actingAs($tutor->user)->postJson(route('lessons.room', $lesson))
        ->assertOk()->assertJson(['participant' => 'tutor', 'token' => 'fake.lesson-'.$lesson->id.'.tutor']);
    $this->actingAs($parent)->postJson(route('lessons.room', $lesson))
        ->assertOk()->assertJson(['participant' => 'learner', 'token' => 'fake.lesson-'.$lesson->id.'.learner']);

    expect($lesson->fresh()->tutor_join_url)->toBeNull()->and($lesson->fresh()->learner_join_url)->toBeNull();
});

it('refuses a token before T-10 min and after the room closes', function () {
    ['lesson' => $lesson, 'parent' => $parent] = rmLesson(14);
    $this->artisan('lessons:create-rooms');

    $this->actingAs($parent)->postJson(route('lessons.room', $lesson))->assertStatus(422);

    Carbon::setTestNow($lesson->starts_at->copy()->subMinutes(10));
    $this->actingAs($parent)->postJson(route('lessons.room', $lesson))->assertOk();

    Carbon::setTestNow($lesson->ends_at->copy()->addMinutes(10));
    $this->actingAs($parent)->postJson(route('lessons.room', $lesson))->assertStatus(422);
});

it('refuses a token while the lesson has no room yet', function () {
    ['lesson' => $lesson, 'parent' => $parent] = rmLesson(5);

    $this->actingAs($parent)->postJson(route('lessons.room', $lesson))->assertStatus(422);
});

it('gives another parent, another tutor, an admin and a guest no token', function () {
    ['lesson' => $lesson] = rmLesson(5);
    $this->artisan('lessons:create-rooms');

    $this->actingAs(User::factory()->create())->postJson(route('lessons.room', $lesson))->assertForbidden();
    $this->actingAs(TutorProfile::factory()->approved()->create()->user)->postJson(route('lessons.room', $lesson))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->postJson(route('lessons.room', $lesson))->assertForbidden();
    auth()->logout();
    $this->postJson(route('lessons.room', $lesson))->assertUnauthorized();
});

it('mints the token through the lesson\'s own provider after a switch', function () {
    rmUseDaily();
    rmFakeDaily();
    ['lesson' => $lesson, 'parent' => $parent] = rmLesson(5);
    $this->artisan('lessons:create-rooms');
    rmUseFake();

    $this->actingAs($parent)->postJson(route('lessons.room', $lesson))->assertOk()->assertJson(['token' => 'tok-1', 'url' => 'https://x.daily.co/lesson-'.$lesson->id]);
});

it('expires the overlap lock of each lifecycle command after ten minutes, not a day', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command, 'lessons:create-rooms')
            || str_contains($event->command, 'lessons:close-rooms')
            || str_contains($event->command, 'lessons:settle-ended'));

    expect($events)->toHaveCount(3)
        ->and($events->every(fn ($event) => $event->withoutOverlapping && $event->expiresAt === 10))->toBeTrue();
});
