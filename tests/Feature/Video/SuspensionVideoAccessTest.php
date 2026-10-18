<?php

use App\Actions\Admin\ReinstateAccount;
use App\Actions\Admin\SuspendAccount;
use App\Actions\Video\CloseSuspendedAccountRooms;
use App\Actions\Video\IssueJoinToken;
use App\Enums\CurriculumCode;
use App\Enums\LessonStatus;
use App\Enums\UserStatus;
use App\Exceptions\AttendanceException;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Models\VideoProvider;
use App\Services\Video\AccountSuspensions;
use App\Services\Video\FakeVideoProvider;
use Illuminate\Support\Carbon;

/**
 * R232: a suspension ends video access. (a) open rooms of the suspended account are closed, (b) a join stamped
 * while the participant was suspended does not count, (c) the Daily payload is covered in DailyVideoProviderTest.
 */
beforeEach(function () {
    FakeVideoProvider::forgetClosedRooms();

    $fake = VideoProvider::query()->where('code', 'fake')->firstOrFail();
    $fake->credentials = ['webhook_secret' => FAKE_WEBHOOK_SECRET];
    $fake->save();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A lesson of a fresh approved tutor and a fresh parent, with a room already made by the fake driver.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User, admin: User}
 */
function suspLesson(array $overrides = [], bool $withRoom = true): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);

    $lesson = Lesson::factory()->create($overrides + [
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addMinutes(5),
        'ends_at' => now()->addMinutes(65),
    ]);

    if ($withRoom) {
        $lesson->forceFill(['room_provider' => 'fake', 'room_id' => 'lesson-'.$lesson->id, 'room_created_at' => now()])->save();
    }

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'parent' => $parent, 'admin' => User::factory()->admin()->create()];
}

/**
 * A further lesson of an existing tutor (the learner and parent are fresh), `$offset` minutes later than the first.
 *
 * @param  array<string, mixed>  $overrides
 */
function suspExtra(TutorProfile $tutor, int $offset, array $overrides = [], bool $withRoom = true): Lesson
{
    return suspLesson($overrides + [
        'tutor_profile_id' => $tutor->id,
        'starts_at' => now()->addMinutes(5 + $offset),
        'ends_at' => now()->addMinutes(65 + $offset),
    ], $withRoom)['lesson'];
}

function suspRoomAudits(Lesson $lesson)
{
    return AuditLog::query()->where('action', 'lesson.room_closed_suspension')->where('subject_type', Lesson::class)->where('subject_id', $lesson->id)->get();
}

function suspJoin(Lesson $lesson, string $participant, int $at)
{
    $body = webhookBody('evt-'.uniqid(), [
        'type' => 'participant.joined',
        'event_ts' => $at,
        'payload' => ['room' => 'lesson-'.$lesson->id, 'user_id' => $participant],
    ]);

    return postWebhook('fake', $body, signedHeaders($body));
}

// (a) --------------------------------------------------------------------------------------------------------------

it('closes the open room when the tutor is suspended', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'admin' => $admin] = suspLesson();

    app(SuspendAccount::class)($admin, $tutor->user, 'Safeguarding concern upheld.');

    expect(FakeVideoProvider::closedRooms())->toBe(['lesson-'.$lesson->id])
        ->and($lesson->fresh()->room_closed_at)->not->toBeNull();

    $audit = suspRoomAudits($lesson)->sole();
    expect($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->after)->toBe(['account_user_id' => $tutor->user_id]);
});

it('closes the open room when the parent is suspended', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'admin' => $admin] = suspLesson();

    app(SuspendAccount::class)($admin, $parent, 'Safeguarding concern upheld.');

    expect(FakeVideoProvider::closedRooms())->toBe(['lesson-'.$lesson->id])
        ->and($lesson->fresh()->room_closed_at)->not->toBeNull()
        ->and(suspRoomAudits($lesson))->toHaveCount(1);
});

it('closes the room of an in-progress lesson', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'admin' => $admin] = suspLesson([
        'status' => LessonStatus::InProgress,
        'starts_at' => now()->subMinutes(10),
        'ends_at' => now()->addMinutes(50),
    ]);

    app(SuspendAccount::class)($admin, $tutor->user, 'Safeguarding concern upheld.');

    expect($lesson->fresh()->room_closed_at)->not->toBeNull()
        ->and(FakeVideoProvider::closedRooms())->toBe(['lesson-'.$lesson->id]);
});

it('closes the room of a confirmed lesson whatever the cancellation sweep does with it', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'admin' => $admin] = suspLesson();

    app(SuspendAccount::class)($admin, $tutor->user, 'Safeguarding concern upheld.');

    // The room sweep is not filtered by status: a lesson the tutor sweep cancels (or leaves for an admin) keeps no live room.
    expect($lesson->fresh()->room_closed_at)->not->toBeNull()
        ->and(FakeVideoProvider::closedRooms())->toBe(['lesson-'.$lesson->id]);
});

it('leaves alone lessons with no room, a closed room, an expired window, or another account', function () {
    ['tutor' => $tutor, 'parent' => $parent, 'admin' => $admin, 'lesson' => $open] = suspLesson();

    $noRoom = suspExtra($tutor, 100, withRoom: false);
    $closed = suspExtra($tutor, 200);
    $closed->forceFill(['room_closed_at' => now()->subMinute()])->save();
    $expired = suspExtra($tutor, 0, ['starts_at' => now()->subHours(3), 'ends_at' => now()->subHours(2)]);
    $other = suspLesson()['lesson'];

    app(SuspendAccount::class)($admin, $tutor->user, 'Safeguarding concern upheld.');

    expect(FakeVideoProvider::closedRooms())->toBe(['lesson-'.$open->id])
        ->and($noRoom->fresh()->room_closed_at)->toBeNull()
        ->and($closed->fresh()->room_closed_at->toIso8601String())->toBe($closed->room_closed_at->toIso8601String())
        ->and($expired->fresh()->room_closed_at)->toBeNull()
        ->and($other->fresh()->room_closed_at)->toBeNull()
        ->and(suspRoomAudits($noRoom))->toHaveCount(0)
        ->and(suspRoomAudits($other))->toHaveCount(0);
});

it('audits each lesson once however many times the sweep runs', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'admin' => $admin] = suspLesson();
    app(SuspendAccount::class)($admin, $tutor->user, 'Safeguarding concern upheld.');

    $again = app(CloseSuspendedAccountRooms::class)($tutor->user->fresh(), $admin);

    expect($again)->toBe(['closed' => 0, 'failed' => 0])
        ->and(suspRoomAudits($lesson))->toHaveCount(1);
});

it('does nothing for an account that is not suspended', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'admin' => $admin] = suspLesson();
    app(SuspendAccount::class)($admin, $tutor->user, 'Safeguarding concern upheld.');
    app(ReinstateAccount::class)($admin, $tutor->user->fresh());

    $second = suspExtra($tutor, 100);
    $result = app(CloseSuspendedAccountRooms::class)($tutor->user->fresh(), $admin);

    expect($result)->toBe(['closed' => 0, 'failed' => 0])
        ->and($second->fresh()->room_closed_at)->toBeNull();
});

it('reports a provider failure for one lesson and still closes the others', function () {
    ['lesson' => $a, 'tutor' => $tutor, 'admin' => $admin] = suspLesson();
    $b = suspExtra($tutor, 100);
    $b->forceFill(['room_provider' => 'unknown-provider'])->save();
    $c = suspExtra($tutor, 200);

    $tutor->user->forceFill(['status' => UserStatus::Suspended])->save();
    $result = app(CloseSuspendedAccountRooms::class)($tutor->user->fresh(), $admin);

    expect($result)->toBe(['closed' => 2, 'failed' => 1])
        ->and($a->fresh()->room_closed_at)->not->toBeNull()
        ->and($b->fresh()->room_closed_at)->toBeNull()
        ->and($c->fresh()->room_closed_at)->not->toBeNull();

    // The retry closes what is left and audits it once.
    $b->forceFill(['room_provider' => 'fake'])->save();
    expect(app(CloseSuspendedAccountRooms::class)($tutor->user->fresh(), $admin))->toBe(['closed' => 1, 'failed' => 0])
        ->and(suspRoomAudits($b))->toHaveCount(1);
});

it('refuses a join token once the room has been closed, and does not recreate it', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent, 'admin' => $admin] = suspLesson();

    app(SuspendAccount::class)($admin, $tutor->user, 'Safeguarding concern upheld.');
    $lesson = $lesson->fresh()->load('learner', 'tutorProfile');

    expect(fn () => IssueJoinToken::assertJoinable($lesson))
        ->toThrow(AttendanceException::class, 'The room for this lesson has closed.');

    expect(fn () => app(IssueJoinToken::class)($parent, $lesson))
        ->toThrow(AttendanceException::class, 'The room for this lesson has closed.');
    expect(FakeVideoProvider::closedRooms())->toBe(['lesson-'.$lesson->id]);
});

// (b) --------------------------------------------------------------------------------------------------------------

it('lets a join stamped before the suspension stand, even when delivered after it', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'admin' => $admin] = suspLesson(['starts_at' => now()->subMinutes(2), 'ends_at' => now()->addMinutes(58)]);
    $joinAt = now()->subMinute()->getTimestamp();

    Carbon::setTestNow(now()->addMinutes(2));
    app(SuspendAccount::class)($admin, $tutor->user, 'Safeguarding concern upheld.');

    suspJoin($lesson, 'tutor', $joinAt)->assertOk();

    expect($lesson->fresh()->tutor_joined_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'attendance.ignored_suspended')->count())->toBe(0);
});

it('ignores a tutor join stamped after the suspension and audits it', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'admin' => $admin] = suspLesson(['starts_at' => now()->subMinutes(2), 'ends_at' => now()->addMinutes(58)]);

    app(SuspendAccount::class)($admin, $tutor->user, 'Safeguarding concern upheld.');
    Carbon::setTestNow(now()->addMinutes(1));

    suspJoin($lesson, 'tutor', now()->getTimestamp())->assertOk();

    $lesson->refresh();
    expect($lesson->tutor_joined_at)->toBeNull();

    $audit = AuditLog::query()->where('action', 'attendance.ignored_suspended')->sole();
    expect($audit->subject_id)->toBe($lesson->id)
        ->and($audit->actor_user_id)->toBeNull()
        ->and($audit->after['participant'])->toBe('tutor')
        ->and($audit->after['user_id'])->toBe($tutor->user_id);
});

it('ignores a learner join stamped after the account holder was suspended', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'admin' => $admin] = suspLesson(['starts_at' => now()->subMinutes(2), 'ends_at' => now()->addMinutes(58)]);

    app(SuspendAccount::class)($admin, $parent, 'Safeguarding concern upheld.');
    Carbon::setTestNow(now()->addMinutes(1));

    suspJoin($lesson, 'learner', now()->getTimestamp())->assertOk();

    expect($lesson->fresh()->learner_joined_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'attendance.ignored_suspended')->sole()->after['user_id'])->toBe($parent->id);
});

it('counts a join stamped after the account was reinstated', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'admin' => $admin] = suspLesson(['starts_at' => now()->subMinutes(2), 'ends_at' => now()->addMinutes(58)]);

    app(SuspendAccount::class)($admin, $tutor->user, 'Safeguarding concern upheld.');
    Carbon::setTestNow(now()->addMinutes(1));
    app(ReinstateAccount::class)($admin, $tutor->user->fresh());
    Carbon::setTestNow(now()->addMinutes(1));

    suspJoin($lesson, 'tutor', now()->getTimestamp())->assertOk();

    expect($lesson->fresh()->tutor_joined_at)->not->toBeNull();
});

it('reads a join in the same second as the suspension as suspended', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'admin' => $admin] = suspLesson(['starts_at' => now()->subMinutes(2), 'ends_at' => now()->addMinutes(58)]);

    Carbon::setTestNow(now()->startOfSecond());
    app(SuspendAccount::class)($admin, $tutor->user, 'Safeguarding concern upheld.');

    suspJoin($lesson, 'tutor', now()->getTimestamp())->assertOk();

    expect($lesson->fresh()->tutor_joined_at)->toBeNull();
});

// AccountSuspensions -----------------------------------------------------------------------------------------------

it('derives "suspended at T" from the last suspension or reinstatement at or before T', function () {
    $user = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $t0 = Carbon::parse('2026-10-18 10:00:00', 'UTC');

    expect(AccountSuspensions::wasSuspendedAt($user->id, $t0))->toBeFalse();

    Carbon::setTestNow($t0->copy()->addMinutes(1));
    app(SuspendAccount::class)($admin, $user, 'Safeguarding concern upheld.');
    Carbon::setTestNow($t0->copy()->addMinutes(3));
    app(ReinstateAccount::class)($admin, $user->fresh());
    Carbon::setTestNow($t0->copy()->addMinutes(5));
    app(SuspendAccount::class)($admin, $user->fresh(), 'Second concern upheld.');

    expect(AccountSuspensions::wasSuspendedAt($user->id, $t0))->toBeFalse()
        ->and(AccountSuspensions::wasSuspendedAt($user->id, $t0->copy()->addMinutes(1)))->toBeTrue()
        ->and(AccountSuspensions::wasSuspendedAt($user->id, $t0->copy()->addMinutes(2)))->toBeTrue()
        ->and(AccountSuspensions::wasSuspendedAt($user->id, $t0->copy()->addMinutes(3)))->toBeFalse()
        ->and(AccountSuspensions::wasSuspendedAt($user->id, $t0->copy()->addMinutes(4)))->toBeFalse()
        ->and(AccountSuspensions::wasSuspendedAt($user->id, $t0->copy()->addMinutes(6)))->toBeTrue()
        ->and(AccountSuspensions::wasSuspendedAt($user->id + 999, $t0->copy()->addMinutes(6)))->toBeFalse();
});

it('breaks a same-second suspend and reinstate by the later row', function () {
    $user = User::factory()->create();
    $admin = User::factory()->admin()->create();
    Carbon::setTestNow(Carbon::parse('2026-10-18 10:00:00', 'UTC'));

    app(SuspendAccount::class)($admin, $user, 'Concern upheld.');
    app(ReinstateAccount::class)($admin, $user->fresh());

    expect(AccountSuspensions::wasSuspendedAt($user->id, now()))->toBeFalse();
});
