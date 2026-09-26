<?php

use App\Actions\Video\ActivateVideoProvider;
use App\Enums\CurriculumCode;
use App\Enums\LessonStatus;
use App\Enums\VideoParticipant;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Models\VideoProvider;
use App\Models\VideoWebhookEvent;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

/**
 * A confirmed lesson with a room already made by `$provider`, starting `$startsInMinutes` from now.
 *
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function atLesson(int $startsInMinutes = 5, string $provider = 'fake'): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);

    $lesson = Lesson::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addMinutes($startsInMinutes),
        'ends_at' => now()->addMinutes($startsInMinutes + 60),
    ]);
    $lesson->forceFill(['room_provider' => $provider, 'room_id' => 'lesson-'.$lesson->id, 'room_created_at' => now()])->save();

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'parent' => $parent];
}

/**
 * Feeds a signed provider-side event through the real webhook route and listener.
 */
function atSend(string $room, string $participant, string $code = 'fake', string $type = 'participant.joined', ?string $id = null, ?int $at = null)
{
    $body = webhookBody($id ?? 'evt-'.uniqid(), [
        'type' => $type,
        'event_ts' => $at ?? time(),
        'payload' => ['room' => $room, 'user_id' => $participant],
    ]);

    return postWebhook($code, $body, signedHeaders($body));
}

beforeEach(function () {
    $fake = VideoProvider::query()->where('code', 'fake')->firstOrFail();
    $fake->credentials = ['webhook_secret' => FAKE_WEBHOOK_SECRET];
    $fake->save();
});

it('records the tutor join from a signed webhook and moves the lesson to in progress', function () {
    ['lesson' => $lesson] = atLesson();
    Carbon::setTestNow(now()->addMinutes(6));

    atSend('lesson-'.$lesson->id, 'tutor', at: now()->getTimestamp())->assertOk();

    $lesson->refresh();
    expect($lesson->status)->toBe(LessonStatus::InProgress)
        ->and($lesson->tutor_joined_at)->not->toBeNull()
        ->and($lesson->learner_joined_at)->toBeNull();
});

it('records the second side without changing the first join time or the status', function () {
    ['lesson' => $lesson] = atLesson();
    atSend('lesson-'.$lesson->id, 'tutor', at: time() - 120);
    $first = $lesson->fresh()->tutor_joined_at;

    atSend('lesson-'.$lesson->id, 'learner', at: time());
    atSend('lesson-'.$lesson->id, 'tutor', at: time()); // a rejoin

    $lesson->refresh();
    expect($lesson->status)->toBe(LessonStatus::InProgress)
        ->and($lesson->learner_joined_at)->not->toBeNull()
        ->and($lesson->tutor_joined_at->toIso8601String())->toBe($first->toIso8601String());
});

it('ignores a left event, an unknown room, an unknown participant, and a room of a missing lesson', function () {
    ['lesson' => $lesson] = atLesson();

    atSend('lesson-'.$lesson->id, 'tutor', type: 'participant.left')->assertOk();
    atSend('lesson-'.$lesson->id, 'stranger')->assertOk();
    atSend('some-other-room', 'tutor')->assertOk();
    atSend('lesson-999999999', 'tutor')->assertOk();

    $lesson->refresh();
    expect($lesson->status)->toBe(LessonStatus::Confirmed)->and($lesson->tutor_joined_at)->toBeNull();
});

it('ignores an event from a provider that is not the one that made the lesson\'s room', function () {
    ['lesson' => $lesson] = atLesson(5, 'daily');

    // A correctly signed *fake* event for a Daily room: a forged attendance for a real lesson.
    atSend('lesson-'.$lesson->id, 'tutor', 'fake')->assertOk();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed)->and($lesson->fresh()->tutor_joined_at)->toBeNull();
});

it('ignores an event whose room is not the lesson\'s recorded room', function () {
    ['lesson' => $lesson] = atLesson();
    $lesson->forceFill(['room_id' => 'lesson-'.$lesson->id.'-old'])->save();

    atSend('lesson-'.$lesson->id, 'tutor')->assertOk();

    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
});

it('ignores attendance for a lesson that is not confirmed or in progress', function () {
    foreach ([LessonStatus::Completed, LessonStatus::CancelledByParent, LessonStatus::Refunded] as $status) {
        ['lesson' => $lesson] = atLesson();
        Lesson::query()->whereKey($lesson->id)->update(['status' => $status]);

        atSend('lesson-'.$lesson->id, 'tutor')->assertOk();

        expect($lesson->fresh()->tutor_joined_at)->toBeNull()->and($lesson->fresh()->status)->toBe($status);
    }
});

it('does not apply a replayed event twice', function () {
    ['lesson' => $lesson] = atLesson();

    atSend('lesson-'.$lesson->id, 'tutor', id: 'evt-once')->assertJson(['status' => 'received']);
    $at = $lesson->fresh()->tutor_joined_at;
    atSend('lesson-'.$lesson->id, 'tutor', id: 'evt-once')->assertJson(['status' => 'duplicate']);

    expect(VideoWebhookEvent::query()->count())->toBe(1)
        ->and($lesson->fresh()->tutor_joined_at->toIso8601String())->toBe($at->toIso8601String());
});

it('rejects a forged webhook and leaves the lesson alone', function () {
    ['lesson' => $lesson] = atLesson();
    $body = webhookBody('evt-forged', ['payload' => ['room' => 'lesson-'.$lesson->id, 'user_id' => 'tutor']]);

    postWebhook('fake', $body, signedHeaders($body, null, 'wrong-secret'))->assertStatus(401);

    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed)->and($lesson->fresh()->tutor_joined_at)->toBeNull();
});

it('still applies attendance from the lesson\'s own provider after another provider is activated', function () {
    ['lesson' => $lesson] = atLesson(5, 'fake');
    $daily = VideoProvider::query()->where('code', 'daily')->firstOrFail();
    $daily->credentials = ['api_key' => 'k', 'webhook_secret' => base64_encode('s')];
    $daily->save();
    app(ActivateVideoProvider::class)($daily, null);

    atSend('lesson-'.$lesson->id, 'tutor', 'fake')->assertOk();

    expect($lesson->fresh()->status)->toBe(LessonStatus::InProgress);
});

it('dispatches the applied event synchronously inside the webhook request', function () {
    ['lesson' => $lesson] = atLesson();

    atSend('lesson-'.$lesson->id, 'learner')->assertOk();

    // No queue worker ran: the effect is already there when the response returns.
    expect($lesson->fresh()->learner_joined_at)->not->toBeNull();
});

// ---- manual "I've joined" ---------------------------------------------------------------------------

it('accepts a manual "I have joined" when the lesson\'s provider sends no webhooks', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = atLesson(5);

    $this->actingAs($parent)->post(route('lessons.joined', $lesson))->assertRedirect();
    $this->actingAs($tutor->user)->post(route('lessons.joined', $lesson))->assertRedirect();

    $lesson->refresh();
    expect($lesson->status)->toBe(LessonStatus::InProgress)
        ->and($lesson->learner_joined_at)->not->toBeNull()
        ->and($lesson->tutor_joined_at)->not->toBeNull();
});

it('refuses a manual join when the lesson\'s provider records attendance itself', function () {
    ['lesson' => $lesson, 'parent' => $parent] = atLesson(5, 'daily');

    $this->actingAs($parent)->post(route('lessons.joined', $lesson))->assertRedirect();

    expect($lesson->fresh()->learner_joined_at)->toBeNull()->and($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
});

it('refuses a manual join before T-10 min and after the room window', function () {
    ['lesson' => $lesson, 'parent' => $parent] = atLesson(30);

    $this->actingAs($parent)->post(route('lessons.joined', $lesson))->assertRedirect();
    expect($lesson->fresh()->learner_joined_at)->toBeNull();

    Carbon::setTestNow($lesson->ends_at->copy()->addMinutes(10));
    $this->actingAs($parent)->post(route('lessons.joined', $lesson))->assertRedirect();
    expect($lesson->fresh()->learner_joined_at)->toBeNull();
});

it('lets nobody but the lesson\'s own parent or tutor say they have joined', function () {
    ['lesson' => $lesson] = atLesson(5);

    $this->actingAs(User::factory()->create())->post(route('lessons.joined', $lesson))->assertForbidden();
    $this->actingAs(TutorProfile::factory()->approved()->create()->user)->post(route('lessons.joined', $lesson))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->post(route('lessons.joined', $lesson))->assertForbidden();

    expect($lesson->fresh()->learner_joined_at)->toBeNull()->and($lesson->fresh()->tutor_joined_at)->toBeNull();
});

it('names the participant by the login, never by anything the client sends', function () {
    ['lesson' => $lesson, 'parent' => $parent] = atLesson(5);

    $this->actingAs($parent)->post(route('lessons.joined', $lesson), ['participant' => VideoParticipant::Tutor->value])->assertRedirect();

    $lesson->refresh();
    expect($lesson->learner_joined_at)->not->toBeNull()->and($lesson->tutor_joined_at)->toBeNull();
});

it('does not count a join at or after the scheduled end, by webhook or by hand', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = atLesson(5);

    Carbon::setTestNow($lesson->ends_at->copy()->addMinutes(5));

    atSend('lesson-'.$lesson->id, 'tutor', at: now()->getTimestamp())->assertOk();
    $this->actingAs($parent)->post(route('lessons.joined', $lesson))->assertRedirect();

    $lesson->refresh();
    expect($lesson->status)->toBe(LessonStatus::Confirmed)
        ->and($lesson->tutor_joined_at)->toBeNull()
        ->and($lesson->learner_joined_at)->toBeNull();

    // the last second before the end still counts
    Carbon::setTestNow($lesson->ends_at->copy()->subSecond());
    $this->actingAs($tutor->user)->post(route('lessons.joined', $lesson))->assertRedirect();
    expect($lesson->fresh()->tutor_joined_at)->not->toBeNull();
});
