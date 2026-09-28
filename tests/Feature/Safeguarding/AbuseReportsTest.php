<?php

use App\Actions\Safeguarding\FileAbuseReport;
use App\Enums\AbuseReportReason;
use App\Enums\AbuseReportStatus;
use App\Enums\AbuseReportSubjectType;
use App\Enums\CurriculumCode;
use App\Enums\UserStatus;
use App\Exceptions\AbuseReportException;
use App\Mail\Admin\AdminAbuseReportMail;
use App\Models\AbuseReport;
use App\Models\Conversation;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\Mail;

// Local, self-contained helpers: this codebase's precedent (`msgPair()` in MessagingPagesTest,
// `nsSetup()` in NoShowTest) is a helper private to its own file, never shared across test files
// (confirmed by grep — no test file calls a helper defined in another), so relying on another
// file having been loaded first would be a hidden, load-order-dependent coupling.

/**
 * A confirmed, paid lesson between a fresh tutor and a fresh parent — via the real
 * `LedgerService::hold()` call (invariant #1), matching `NoShowTest`'s `nsSetup()` precedent.
 *
 * @return array{lesson: Lesson, tutor: TutorProfile, tutorUser: User, parent: User}
 */
function sgLesson(): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);

    $lesson = Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'learner_id' => $learner->id]);
    Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
    app(LedgerService::class)->hold($lesson);

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'tutorUser' => $tutor->user, 'parent' => $parent];
}

/**
 * A conversation with both parties in hand, mirroring `MessagingPagesTest`'s `msgPair()` shape
 * but kept local to this file (see note above).
 *
 * @return array{conversation: Conversation, parent: User, tutorUser: User, tutor: TutorProfile}
 */
function sgConversation(): array
{
    $parent = User::factory()->create();
    $tutor = TutorProfile::factory()->approved()->create();
    $conversation = Conversation::factory()->create(['account_user_id' => $parent->id, 'tutor_profile_id' => $tutor->id]);

    return ['conversation' => $conversation, 'parent' => $parent, 'tutorUser' => $tutor->user, 'tutor' => $tutor];
}

// ---- filing: success -----------------------------------------------------------------------

it('lets a parent file a report against a tutor profile, creating an open row and redirecting back', function () {
    $parent = User::factory()->create();
    $tutor = TutorProfile::factory()->approved()->create();

    test()->actingAs($parent)
        ->post(route('abuse-reports.tutor.store', $tutor), ['reason' => 'safety', 'description' => 'A concern.'])
        ->assertRedirect(route('tutors.show', $tutor));

    $report = AbuseReport::query()->sole();
    expect($report->reporter_user_id)->toBe($parent->id)
        ->and($report->subject_type)->toBe(AbuseReportSubjectType::TutorProfile)
        ->and($report->subject_id)->toBe($tutor->id)
        ->and($report->reason)->toBe(AbuseReportReason::Safety)
        ->and($report->description)->toBe('A concern.')
        ->and($report->status)->toBe(AbuseReportStatus::Open);
});

it('lets either party on a lesson file a report against it', function () {
    ['lesson' => $lesson, 'parent' => $parent, 'tutorUser' => $tutorUser] = sgLesson();

    test()->actingAs($parent)
        ->post(route('abuse-reports.lesson.store', $lesson), ['reason' => 'conduct', 'description' => 'Something felt off.'])
        ->assertRedirect(route('lessons.show', $lesson));

    $report = AbuseReport::query()->sole();
    expect($report->subject_type)->toBe(AbuseReportSubjectType::Lesson)
        ->and($report->subject_id)->toBe($lesson->id)
        ->and($report->reporter_user_id)->toBe($parent->id);

    $report->delete();

    test()->actingAs($tutorUser)
        ->post(route('abuse-reports.lesson.store', $lesson), ['reason' => 'conduct', 'description' => 'Something felt off, from the other side.'])
        ->assertRedirect(route('lessons.show', $lesson));

    expect(AbuseReport::query()->sole()->reporter_user_id)->toBe($tutorUser->id);
});

it('lets either party on a conversation file a report against it', function () {
    ['conversation' => $conversation, 'parent' => $parent] = sgConversation();

    test()->actingAs($parent)
        ->post(route('abuse-reports.conversation.store', $conversation), ['reason' => 'contact_sharing', 'description' => 'They asked to move off-platform.'])
        ->assertRedirect(route('messages.show', $conversation));

    $report = AbuseReport::query()->sole();
    expect($report->subject_type)->toBe(AbuseReportSubjectType::Conversation)
        ->and($report->subject_id)->toBe($conversation->id);
});

// ---- filing: authorization ---------------------------------------------------------------------

it('tells a stranger to a lesson or conversation there is no such page rather than filing on their behalf', function () {
    // A tutor profile has no "party" requirement — TutorProfilePolicy::reportAbuse deliberately
    // lets any signed-in account holder or tutor report any profile (its own docblock says so);
    // only the profile's own owner is refused, covered separately below.
    $stranger = User::factory()->create();
    ['lesson' => $lesson] = sgLesson();
    ['conversation' => $conversation] = sgConversation();

    test()->actingAs($stranger)->post(route('abuse-reports.lesson.store', $lesson), ['reason' => 'safety', 'description' => 'x'])->assertNotFound();
    test()->actingAs($stranger)->post(route('abuse-reports.conversation.store', $conversation), ['reason' => 'safety', 'description' => 'x'])->assertNotFound();

    expect(AbuseReport::query()->count())->toBe(0);
});

it('refuses a tutor reporting their own profile', function () {
    $tutor = TutorProfile::factory()->approved()->create();

    test()->actingAs($tutor->user)
        ->post(route('abuse-reports.tutor.store', $tutor), ['reason' => 'safety', 'description' => 'x'])
        ->assertNotFound();

    expect(AbuseReport::query()->count())->toBe(0);
});

it('refuses a guest entirely (auth middleware, before the policy is ever reached)', function () {
    $tutor = TutorProfile::factory()->approved()->create();

    test()->post(route('abuse-reports.tutor.store', $tutor), ['reason' => 'safety', 'description' => 'x'])
        ->assertRedirect(route('login'));
});

// ---- filing: defence in depth for a suspended reporter --------------------------------------

it('refuses to file for a reporter whose account is not active, even called directly', function () {
    $reporter = User::factory()->create(['status' => UserStatus::Suspended]);
    $tutor = TutorProfile::factory()->approved()->create();

    expect(fn () => app(FileAbuseReport::class)($reporter, $tutor, AbuseReportReason::Safety, 'x'))
        ->toThrow(AbuseReportException::class);

    expect(AbuseReport::query()->count())->toBe(0);
});

// ---- filing: validation ----------------------------------------------------------------------

it('requires a valid reason and a description, and rejects one over 2000 characters while accepting exactly 2000', function () {
    $parent = User::factory()->create();
    $tutor = TutorProfile::factory()->approved()->create();

    test()->actingAs($parent)->post(route('abuse-reports.tutor.store', $tutor), ['reason' => '', 'description' => ''])
        ->assertSessionHasErrors(['reason', 'description']);

    test()->actingAs($parent)->post(route('abuse-reports.tutor.store', $tutor), ['reason' => 'not-a-real-reason', 'description' => 'x'])
        ->assertSessionHasErrors('reason');

    test()->actingAs($parent)->post(route('abuse-reports.tutor.store', $tutor), ['reason' => 'safety', 'description' => str_repeat('x', 2001)])
        ->assertSessionHasErrors('description');

    expect(AbuseReport::query()->count())->toBe(0);

    test()->actingAs($parent)->post(route('abuse-reports.tutor.store', $tutor), ['reason' => 'safety', 'description' => str_repeat('x', 2000)])
        ->assertRedirect(route('tutors.show', $tutor));

    expect(AbuseReport::query()->sole()->description)->toHaveLength(2000);
});

// ---- filing: throttling ------------------------------------------------------------------------

it('lets 5 reports through in an hour and rejects the 6th with 429', function () {
    $parent = User::factory()->create();
    $tutors = TutorProfile::factory()->approved()->count(6)->create();

    foreach ($tutors->take(5) as $tutor) {
        test()->actingAs($parent)
            ->post(route('abuse-reports.tutor.store', $tutor), ['reason' => 'safety', 'description' => 'x'])
            ->assertRedirect(route('tutors.show', $tutor));
    }

    test()->actingAs($parent)
        ->post(route('abuse-reports.tutor.store', $tutors->last()), ['reason' => 'safety', 'description' => 'x'])
        ->assertStatus(429);

    expect(AbuseReport::query()->count())->toBe(5);
});

// ---- FileAbuseReport: server-side subject resolution ------------------------------------------

it('resolves subject_type and subject_id from the model Laravel bound, never from client input', function () {
    $parent = User::factory()->create();
    $tutor = TutorProfile::factory()->approved()->create();
    ['lesson' => $lesson] = sgLesson();
    $conversation = Conversation::factory()->create(['account_user_id' => $parent->id]);

    $reportOnTutor = app(FileAbuseReport::class)($parent, $tutor, AbuseReportReason::Safety, 'x');
    expect($reportOnTutor->subject_type)->toBe(AbuseReportSubjectType::TutorProfile)->and($reportOnTutor->subject_id)->toBe($tutor->id);

    $reportOnLesson = app(FileAbuseReport::class)($lesson->learner->account, $lesson, AbuseReportReason::Safety, 'x');
    expect($reportOnLesson->subject_type)->toBe(AbuseReportSubjectType::Lesson)->and($reportOnLesson->subject_id)->toBe($lesson->id);

    $reportOnConversation = app(FileAbuseReport::class)($parent, $conversation, AbuseReportReason::Safety, 'x');
    expect($reportOnConversation->subject_type)->toBe(AbuseReportSubjectType::Conversation)->and($reportOnConversation->subject_id)->toBe($conversation->id);

    $anotherUser = User::factory()->create();
    $reportOnUser = app(FileAbuseReport::class)($parent, $anotherUser, AbuseReportReason::Safety, 'x');
    expect($reportOnUser->subject_type)->toBe(AbuseReportSubjectType::User)->and($reportOnUser->subject_id)->toBe($anotherUser->id);
});

// ---- AbuseReport::reportedUser() ---------------------------------------------------------------

it('resolves the reported party for a tutor-profile subject', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $report = AbuseReport::factory()->create(['subject_type' => AbuseReportSubjectType::TutorProfile, 'subject_id' => $tutor->id]);

    expect($report->reportedUser()->is($tutor->user))->toBeTrue();
});

it('resolves the reported party for a plain user subject', function () {
    $target = User::factory()->create();
    $report = AbuseReport::factory()->create(['subject_type' => AbuseReportSubjectType::User, 'subject_id' => $target->id]);

    expect($report->reportedUser()->is($target))->toBeTrue();
});

it('resolves the reported party for a lesson subject as whichever side did not file, always the account holder not the learner', function () {
    ['lesson' => $lesson, 'tutorUser' => $tutorUser, 'parent' => $parent] = sgLesson();

    $filedByParent = AbuseReport::factory()->create([
        'reporter_user_id' => $parent->id,
        'subject_type' => AbuseReportSubjectType::Lesson,
        'subject_id' => $lesson->id,
    ]);
    expect($filedByParent->reportedUser()->is($tutorUser))->toBeTrue();

    $filedByTutor = AbuseReport::factory()->create([
        'reporter_user_id' => $tutorUser->id,
        'subject_type' => AbuseReportSubjectType::Lesson,
        'subject_id' => $lesson->id,
    ]);
    expect($filedByTutor->reportedUser()->is($parent))->toBeTrue();
});

it('resolves the reported party for a conversation subject the same way', function () {
    ['conversation' => $conversation, 'parent' => $parent, 'tutorUser' => $tutorUser] = sgConversation();

    $filedByParent = AbuseReport::factory()->create([
        'reporter_user_id' => $parent->id,
        'subject_type' => AbuseReportSubjectType::Conversation,
        'subject_id' => $conversation->id,
    ]);
    expect($filedByParent->reportedUser()->is($tutorUser))->toBeTrue();

    $filedByTutor = AbuseReport::factory()->create([
        'reporter_user_id' => $tutorUser->id,
        'subject_type' => AbuseReportSubjectType::Conversation,
        'subject_id' => $conversation->id,
    ]);
    expect($filedByTutor->reportedUser()->is($parent))->toBeTrue();
});

it('returns null from reportedUser() when the subject row no longer resolves', function () {
    $report = AbuseReport::factory()->create(['subject_type' => AbuseReportSubjectType::Lesson, 'subject_id' => 999_999]);

    expect($report->reportedUser())->toBeNull();
});

// ---- the admin-only mail chain ------------------------------------------------------------------

it('emails admins only when a report is filed, and never the reported party', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $parent = User::factory()->create();
    $tutor = TutorProfile::factory()->approved()->create();

    test()->actingAs($parent)
        ->post(route('abuse-reports.tutor.store', $tutor), ['reason' => 'safety', 'description' => 'A concern.'])
        ->assertRedirect(route('tutors.show', $tutor));

    Mail::assertQueued(AdminAbuseReportMail::class, 1);
    Mail::assertQueued(AdminAbuseReportMail::class, fn (AdminAbuseReportMail $mail): bool => $mail->hasTo($admin->email));
    Mail::assertNotQueued(AdminAbuseReportMail::class, fn (AdminAbuseReportMail $mail): bool => $mail->hasTo($tutor->user->email));
});

it('never emails a suspended admin', function () {
    Mail::fake();
    $suspendedAdmin = User::factory()->admin()->create(['status' => UserStatus::Suspended]);
    $activeAdmin = User::factory()->admin()->create();
    $parent = User::factory()->create();
    $tutor = TutorProfile::factory()->approved()->create();

    test()->actingAs($parent)->post(route('abuse-reports.tutor.store', $tutor), ['reason' => 'safety', 'description' => 'x']);

    Mail::assertQueued(AdminAbuseReportMail::class, 1);
    Mail::assertQueued(AdminAbuseReportMail::class, fn (AdminAbuseReportMail $mail): bool => $mail->hasTo($activeAdmin->email));
    Mail::assertNotQueued(AdminAbuseReportMail::class, fn (AdminAbuseReportMail $mail): bool => $mail->hasTo($suspendedAdmin->email));
});
