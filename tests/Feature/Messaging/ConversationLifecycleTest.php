<?php

use App\Actions\Messaging\EnsureConversation;
use App\Enums\CurriculumCode;
use App\Enums\LessonStatus;
use App\Enums\SettingGroup;
use App\Events\Lessons\LessonStatusChanged;
use App\Models\Conversation;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Lessons\LessonStateMachine;
use App\Support\Facades\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

afterEach(fn () => Carbon::setTestNow());

/** A distinct start for each lesson made, because one tutor cannot hold two lessons at the same time. */
function msgNextSlot(): int
{
    static $n = 0;

    return $n++;
}

/**
 * One lesson of a fresh account/tutor pair in the given status, made straight through the factory (which
 * fires no event), so each test decides which event the listener hears.
 *
 * @return array{lesson: Lesson, parent: User, tutor: TutorProfile}
 */
function msgLesson(LessonStatus $status = LessonStatus::PendingPayment, ?User $parent = null, ?TutorProfile $tutor = null): array
{
    $tutor ??= TutorProfile::factory()->approved()->create();
    $parent ??= User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);

    $lesson = Lesson::factory()->withStatus($status)->startingAt(now()->addDays(2 + msgNextSlot()))->create(['tutor_profile_id' => $tutor->id, 'learner_id' => $learner->id]);

    return ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor];
}

function msgConversationFor(User $parent, TutorProfile $tutor): ?Conversation
{
    return Conversation::query()->where('account_user_id', $parent->id)->where('tutor_profile_id', $tutor->id)->first();
}

it('opens the conversation when a lesson becomes confirmed', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = msgLesson(LessonStatus::PendingPayment);

    expect(msgConversationFor($parent, $tutor))->toBeNull();

    LessonStateMachine::transition($lesson, LessonStatus::Confirmed);

    $conversation = msgConversationFor($parent, $tutor);
    expect($conversation)->not->toBeNull()
        ->and($conversation->first_lesson_completed_at)->toBeNull()
        ->and($conversation->last_message_at)->toBeNull();
});

it('opens the conversation when a weekly lesson is created reserved', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = msgLesson(LessonStatus::Reserved);

    LessonStatusChanged::dispatch($lesson, null, LessonStatus::Reserved);

    expect(msgConversationFor($parent, $tutor))->not->toBeNull();
});

it('keeps one row when a reservation is opened and then confirmed by the charge, twice', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = msgLesson(LessonStatus::Reserved);

    LessonStatusChanged::dispatch($lesson, null, LessonStatus::Reserved);
    LessonStatusChanged::dispatch($lesson, LessonStatus::Reserved, LessonStatus::Confirmed);
    LessonStatusChanged::dispatch($lesson, LessonStatus::Reserved, LessonStatus::Confirmed);

    expect(Conversation::query()->where('account_user_id', $parent->id)->where('tutor_profile_id', $tutor->id)->count())->toBe(1);
});

it('opens none for a pending_payment lesson that is created, or that expires', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = msgLesson(LessonStatus::PendingPayment);

    LessonStatusChanged::dispatch($lesson, null, LessonStatus::PendingPayment);
    LessonStateMachine::transition($lesson, LessonStatus::Expired);

    expect(msgConversationFor($parent, $tutor))->toBeNull();
});

it('opens none for a cancelled_payment_failed reservation or an unpaid weekly attempt', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = msgLesson(LessonStatus::Reserved);

    LessonStatusChanged::dispatch($lesson, LessonStatus::Reserved, LessonStatus::CancelledPaymentFailed);

    expect(msgConversationFor($parent, $tutor))->toBeNull();
});

it('keeps one conversation per pair however many lessons confirm, and one per tutor for another parent', function () {
    ['lesson' => $first, 'parent' => $parent, 'tutor' => $tutor] = msgLesson(LessonStatus::PendingPayment);
    ['lesson' => $second] = msgLesson(LessonStatus::PendingPayment, $parent, $tutor);
    ['lesson' => $other, 'parent' => $otherParent] = msgLesson(LessonStatus::PendingPayment, null, $tutor);

    foreach ([$first, $second, $other] as $lesson) {
        LessonStateMachine::transition($lesson, LessonStatus::Confirmed);
    }

    expect(Conversation::query()->count())->toBe(2)
        ->and(msgConversationFor($parent, $tutor))->not->toBeNull()
        ->and(msgConversationFor($otherParent, $tutor))->not->toBeNull();
});

it('is idempotent when the action is called directly', function () {
    ['lesson' => $lesson] = msgLesson(LessonStatus::Confirmed);

    $a = app(EnsureConversation::class)($lesson);
    $b = app(EnsureConversation::class)($lesson);

    expect($a->id)->toBe($b->id)->and(Conversation::query()->count())->toBe(1);
});

it('outlives cancellations', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = msgLesson(LessonStatus::PendingPayment);

    LessonStateMachine::transition($lesson, LessonStatus::Confirmed);
    LessonStateMachine::transition($lesson, LessonStatus::CancelledByParent);

    expect(msgConversationFor($parent, $tutor))->not->toBeNull();
});

it('stamps first_lesson_completed_at when a lesson of the pair enters completed, once', function () {
    Carbon::setTestNow('2026-09-27 10:00:00');
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = msgLesson(LessonStatus::InProgress);

    LessonStateMachine::transition($lesson, LessonStatus::Completed, fn (Lesson $l) => $l->forceFill(['completed_at' => now()]));

    $stamped = msgConversationFor($parent, $tutor)->first_lesson_completed_at;
    expect($stamped)->not->toBeNull()->and($stamped->toDateTimeString())->toBe('2026-09-27 10:00:00');

    Carbon::setTestNow('2026-09-28 10:00:00');
    ['lesson' => $later] = msgLesson(LessonStatus::InProgress, $parent, $tutor);
    LessonStateMachine::transition($later, LessonStatus::Completed, fn (Lesson $l) => $l->forceFill(['completed_at' => now()]));

    expect(msgConversationFor($parent, $tutor)->first_lesson_completed_at->toDateTimeString())->toBe('2026-09-27 10:00:00');
});

it('does not stamp it on a report-only route: a cancelled or no-show lesson is not a completed lesson', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = msgLesson(LessonStatus::InProgress);

    LessonStateMachine::transition($lesson, LessonStatus::NoShowStudent);

    expect(msgConversationFor($parent, $tutor)?->first_lesson_completed_at)->toBeNull();
});

it('creates the conversation itself when completion is heard before confirmation', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = msgLesson(LessonStatus::InProgress);

    LessonStateMachine::transition($lesson, LessonStatus::Completed, fn (Lesson $l) => $l->forceFill(['completed_at' => now()]));
    LessonStatusChanged::dispatch($lesson, LessonStatus::PendingPayment, LessonStatus::Confirmed);

    $conversation = msgConversationFor($parent, $tutor);
    expect($conversation->first_lesson_completed_at)->not->toBeNull()
        ->and(Conversation::query()->count())->toBe(1);
});

it('keeps opening and stamping conversations while messaging is switched off', function () {
    Settings::set('messaging', false, SettingGroup::Features);
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = msgLesson(LessonStatus::PendingPayment);

    LessonStateMachine::transition($lesson, LessonStatus::Confirmed);

    expect(msgConversationFor($parent, $tutor))->not->toBeNull();
});

it('refuses a second conversation for the same pair at the database', function () {
    $conversation = Conversation::factory()->create();

    expect(fn () => DB::table('conversations')->insert([
        'account_user_id' => $conversation->account_user_id,
        'tutor_profile_id' => $conversation->tutor_profile_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('backfills existing pairs, with the earliest completed lesson as the first-lesson date', function () {
    Carbon::setTestNow('2026-09-27 10:00:00');
    ['lesson' => $done, 'parent' => $doneParent, 'tutor' => $doneTutor] = msgLesson(LessonStatus::Completed);
    $done->forceFill(['completed_at' => now()->subDays(3)])->saveQuietly();
    ['lesson' => $later] = msgLesson(LessonStatus::Completed, $doneParent, $doneTutor);
    $later->forceFill(['completed_at' => now()->subDay()])->saveQuietly();
    ['parent' => $paidParent, 'tutor' => $paidTutor] = msgLesson(LessonStatus::Confirmed);
    ['parent' => $unpaidParent, 'tutor' => $unpaidTutor] = msgLesson(LessonStatus::Expired);
    ['parent' => $pendingParent, 'tutor' => $pendingTutor] = msgLesson(LessonStatus::PendingPayment);

    DB::table('conversations')->delete();
    $migration = require database_path('migrations/2026_09_30_100000_create_conversations_and_messages.php');
    $migration->backfill();

    expect(msgConversationFor($doneParent, $doneTutor)->first_lesson_completed_at->toDateTimeString())->toBe('2026-09-24 10:00:00')
        ->and(msgConversationFor($paidParent, $paidTutor))->not->toBeNull()
        ->and(msgConversationFor($paidParent, $paidTutor)->first_lesson_completed_at)->toBeNull()
        ->and(msgConversationFor($unpaidParent, $unpaidTutor))->toBeNull()
        ->and(msgConversationFor($pendingParent, $pendingTutor))->toBeNull();

    $migration->backfill();
    expect(Conversation::query()->count())->toBe(2);
});
