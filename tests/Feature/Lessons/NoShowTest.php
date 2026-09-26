<?php

use App\Actions\Lessons\MarkNoShow;
use App\Actions\Lessons\RecordAttendance;
use App\Enums\CurriculumCode;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\PaymentStatus;
use App\Enums\StrikeType;
use App\Enums\TutorProfileStatus;
use App\Enums\VideoParticipant;
use App\Exceptions\AttendanceException;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\TutorStrike;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

/**
 * A paid, held, confirmed lesson that started `$startedMinutesAgo` minutes ago; `$joined` lists who is
 * already in the room (each goes through `RecordAttendance`, as a webhook would).
 *
 * @param  list<VideoParticipant>  $joined
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function nsSetup(int $startedMinutesAgo, array $joined = []): array
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
        'starts_at' => now()->subMinutes($startedMinutesAgo),
        'ends_at' => now()->subMinutes($startedMinutesAgo)->addHour(),
    ]);
    Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
    app(LedgerService::class)->hold($lesson);
    $lesson->forceFill(['room_provider' => 'fake', 'room_id' => 'lesson-'.$lesson->id, 'room_created_at' => now()->subHour()])->save();

    foreach ($joined as $who) {
        app(RecordAttendance::class)($lesson->fresh(), $who, now());
    }

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'parent' => $parent];
}

// ---- the tutor marks the student absent -------------------------------------------------------------

it('pays the tutor in full when the tutor marks an absent student after the grace', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = nsSetup(16, [VideoParticipant::Tutor]);
    $ledger = app(LedgerService::class);

    $result = app(MarkNoShow::class)($tutor->user, $lesson);

    expect($result->status)->toBe(LessonStatus::CompletedReported)
        ->and($result->escrow_released_at)->not->toBeNull()
        ->and($ledger->sum($lesson))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Escrow))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Tutor))->toBe($lesson->tutor_amount->toFils())
        ->and($ledger->balance($lesson, LedgerAccount::Platform))->toBe($lesson->commission_amount->toFils())
        ->and($ledger->balance($lesson, LedgerAccount::Refund))->toBe(0)
        ->and(TutorStrike::query()->count())->toBe(0);
});

it('refuses the tutor\'s mark before the student grace has passed', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = nsSetup(14, [VideoParticipant::Tutor]);

    expect(fn () => app(MarkNoShow::class)($tutor->user, $lesson))->toThrow(AttendanceException::class);

    expect($lesson->fresh()->status)->toBe(LessonStatus::InProgress)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Tutor))->toBe(0);
});

it('takes the grace from the lesson row, not from the settings', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = nsSetup(10, [VideoParticipant::Tutor]);
    $lesson->forceFill(['student_grace_min' => 5])->save();

    $result = app(MarkNoShow::class)($tutor->user, $lesson->fresh());

    expect($result->status)->toBe(LessonStatus::CompletedReported);
});

it('refuses the tutor\'s mark when the student has joined, or the tutor has not', function () {
    ['lesson' => $joined, 'tutor' => $tutorA] = nsSetup(30, [VideoParticipant::Tutor, VideoParticipant::Learner]);
    ['lesson' => $absent, 'tutor' => $tutorB] = nsSetup(30, [VideoParticipant::Learner]);

    expect(fn () => app(MarkNoShow::class)($tutorA->user, $joined))->toThrow(AttendanceException::class, 'joined')
        ->and(fn () => app(MarkNoShow::class)($tutorB->user, $absent))->toThrow(AttendanceException::class, 'not joined');

    expect($joined->fresh()->status)->toBe(LessonStatus::InProgress)->and($absent->fresh()->status)->toBe(LessonStatus::InProgress);
});

it('refuses a tutor no-show mark on a lesson nobody has joined', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = nsSetup(30);

    expect(fn () => app(MarkNoShow::class)($tutor->user, $lesson))->toThrow(AttendanceException::class);
});

// ---- the parent marks the tutor absent --------------------------------------------------------------

it('refunds the parent, records a strike and pays nobody when the parent marks an absent tutor', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = nsSetup(11, [VideoParticipant::Learner]);
    $ledger = app(LedgerService::class);

    $result = app(MarkNoShow::class)($parent, $lesson);

    expect($result->status)->toBe(LessonStatus::Refunded)
        ->and($result->escrow_released_at)->toBeNull()
        ->and($ledger->sum($lesson))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Refund))->toBe($lesson->price->toFils())
        ->and($ledger->balance($lesson, LedgerAccount::Tutor))->toBe(0)
        ->and($ledger->balance($lesson, LedgerAccount::Platform))->toBe(0);

    $payment = Payment::query()->where('lesson_id', $lesson->id)->sole();
    expect($payment->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->refunded_amount->toFils())->toBe($lesson->price->toFils());

    $strike = TutorStrike::query()->where('tutor_profile_id', $tutor->id)->sole();
    expect($strike->type)->toBe(StrikeType::NoShow)->and($strike->lesson_id)->toBe($lesson->id);
});

it('refuses the parent\'s mark before the tutor grace, or when the tutor joined, or the parent has not', function () {
    ['lesson' => $early, 'parent' => $parentA] = nsSetup(9, [VideoParticipant::Learner]);
    ['lesson' => $both, 'parent' => $parentB] = nsSetup(30, [VideoParticipant::Learner, VideoParticipant::Tutor]);
    ['lesson' => $alone, 'parent' => $parentC] = nsSetup(30, [VideoParticipant::Tutor]);

    expect(fn () => app(MarkNoShow::class)($parentA, $early))->toThrow(AttendanceException::class)
        ->and(fn () => app(MarkNoShow::class)($parentB, $both))->toThrow(AttendanceException::class)
        ->and(fn () => app(MarkNoShow::class)($parentC, $alone))->toThrow(AttendanceException::class);

    expect(TutorStrike::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->where('lesson_id', $early->id)->count())->toBe(2); // the hold only
});

it('suspends a tutor on the third strike inside 90 days', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutor' => $tutor] = nsSetup(11, [VideoParticipant::Learner]);

    foreach ([1, 2] as $n) {
        TutorStrike::query()->create([
            'tutor_profile_id' => $tutor->id,
            'lesson_id' => nsSetup(600)['lesson']->id,
            'type' => StrikeType::NoShow,
            'note' => "earlier {$n}",
        ]);
    }

    app(MarkNoShow::class)($parent, $lesson);

    expect($tutor->fresh()->status)->toBe(TutorProfileStatus::Suspended);
});

it('lets nobody else mark a no-show, and does not mark the same lesson twice', function () {
    ['lesson' => $lesson, 'parent' => $parent] = nsSetup(30, [VideoParticipant::Learner]);

    expect(fn () => app(MarkNoShow::class)(User::factory()->create(), $lesson))->toThrow(AttendanceException::class)
        ->and(fn () => app(MarkNoShow::class)(User::factory()->admin()->create(), $lesson))->toThrow(AttendanceException::class);

    app(MarkNoShow::class)($parent, $lesson);

    expect(fn () => app(MarkNoShow::class)($parent, $lesson->fresh()))->toThrow(AttendanceException::class)
        ->and(TutorStrike::query()->count())->toBe(1)
        ->and(app(LedgerService::class)->balance($lesson, LedgerAccount::Refund))->toBe($lesson->price->toFils());
});

// ---- over HTTP ----------------------------------------------------------------------------------------

it('marks a no-show through the route for the right party and 403s the rest', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = nsSetup(20, [VideoParticipant::Tutor]);

    $this->actingAs(User::factory()->create())->post(route('lessons.no-show', $lesson))->assertForbidden();
    $this->actingAs($parent)->post(route('lessons.no-show', $lesson))->assertRedirect(); // refused: parent not in the room
    expect($lesson->fresh()->status)->toBe(LessonStatus::InProgress);

    $this->actingAs($tutor->user)->post(route('lessons.no-show', $lesson))->assertRedirect();
    expect($lesson->fresh()->status)->toBe(LessonStatus::CompletedReported);
});
