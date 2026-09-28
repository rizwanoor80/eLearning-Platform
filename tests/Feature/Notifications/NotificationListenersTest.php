<?php

use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Events\Lessons\LessonChargeFailed;
use App\Events\Lessons\LessonStatusChanged;
use App\Events\Lessons\ProgressReportSubmitted;
use App\Events\Messaging\MessageSent;
use App\Models\Conversation;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\ProgressReport;
use App\Models\TutorProfile;
use App\Models\User;
use App\Notifications\Lessons\LessonCancelledNotification;
use App\Notifications\Lessons\LessonConfirmedNotification;
use App\Notifications\Lessons\ProgressReportAvailableNotification;
use App\Notifications\Lessons\WeeklyChargeFailedNotification;
use App\Notifications\Messaging\NewMessageNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

/**
 * @return array{lesson: Lesson, tutorUser: User, parentUser: User}
 */
function notifLesson(LessonStatus $status = LessonStatus::Confirmed, array $overrides = []): array
{
    $lesson = Lesson::factory()->withStatus($status)->create($overrides);

    return [
        'lesson' => $lesson,
        'tutorUser' => $lesson->tutorProfile->user,
        'parentUser' => $lesson->learner->account,
    ];
}

// CP7 8e — invariant 7 (a minor never has a login, so is never notified): the model itself can't be
// notified, independent of any listener's own care in choosing who to notify.
it('never lets Learner be Notifiable', function () {
    expect(class_uses_recursive(Learner::class))->not->toHaveKey('Illuminate\Notifications\Notifiable');
});

// --- lesson confirmed ------------------------------------------------------------------------------

it('records a lesson-confirmed bell for both parties, regardless of from', function () {
    Notification::fake();
    ['lesson' => $lesson, 'tutorUser' => $tutorUser, 'parentUser' => $parentUser] = notifLesson();

    event(new LessonStatusChanged($lesson, null, LessonStatus::Confirmed));

    Notification::assertSentTo($parentUser, LessonConfirmedNotification::class);
    Notification::assertSentTo($tutorUser, LessonConfirmedNotification::class);

    // No carve-out for a weekly auto-charge confirmation (from `reserved`) — unlike the mail side.
    event(new LessonStatusChanged($lesson, LessonStatus::Reserved, LessonStatus::Confirmed));

    Notification::assertSentToTimes($parentUser, LessonConfirmedNotification::class, 2);
});

it('does not record a lesson-confirmed bell for a transition that is not into confirmed', function () {
    Notification::fake();
    ['lesson' => $lesson] = notifLesson(LessonStatus::Confirmed);

    event(new LessonStatusChanged($lesson, LessonStatus::Confirmed, LessonStatus::InProgress));

    Notification::assertNothingSent();
});

// --- lesson cancelled --------------------------------------------------------------------------------

it('records a lesson-cancelled bell for a paid cancellation', function () {
    Notification::fake();
    ['lesson' => $lesson, 'tutorUser' => $tutorUser, 'parentUser' => $parentUser] = notifLesson();

    event(new LessonStatusChanged($lesson, LessonStatus::Confirmed, LessonStatus::CancelledByParent));

    Notification::assertSentTo($parentUser, LessonCancelledNotification::class);
    Notification::assertSentTo($tutorUser, LessonCancelledNotification::class);
});

it('records a lesson-cancelled bell for a free skip with a persons own note', function () {
    Notification::fake();
    ['lesson' => $lesson, 'parentUser' => $parentUser] = notifLesson(LessonStatus::Reserved, ['cancel_reason' => 'change of plans']);

    event(new LessonStatusChanged($lesson, LessonStatus::Reserved, LessonStatus::CancelledByTutor));

    Notification::assertSentTo($parentUser, LessonCancelledNotification::class);
});

it('records a lesson-cancelled bell when the tutor becomes unavailable', function () {
    Notification::fake();
    ['lesson' => $lesson, 'parentUser' => $parentUser] = notifLesson(LessonStatus::Reserved, [
        'cancel_reason' => LessonCancelReason::TutorUnavailable->value,
    ]);

    event(new LessonStatusChanged($lesson, LessonStatus::Reserved, LessonStatus::CancelledByTutor));

    Notification::assertSentTo($parentUser, LessonCancelledNotification::class);
});

it('records a lesson-cancelled bell for a failed weekly charge', function () {
    Notification::fake();
    ['lesson' => $lesson, 'parentUser' => $parentUser] = notifLesson(LessonStatus::Reserved);

    event(new LessonStatusChanged($lesson, LessonStatus::Reserved, LessonStatus::CancelledPaymentFailed));

    Notification::assertSentTo($parentUser, LessonCancelledNotification::class);
});

it('stays silent on a reserved cancellation whose machine reason has its own separate story', function (string $reason) {
    Notification::fake();
    ['lesson' => $lesson] = notifLesson(LessonStatus::Reserved, ['cancel_reason' => $reason]);

    event(new LessonStatusChanged($lesson, LessonStatus::Reserved, LessonStatus::CancelledByTutor));

    Notification::assertNothingSent();
})->with([
    'slot paused' => [LessonCancelReason::SlotPaused->value],
    'slot ended' => [LessonCancelReason::SlotEnded->value],
    'tutor suspended' => [LessonCancelReason::TutorSuspended->value],
    'account suspended' => [LessonCancelReason::AccountSuspended->value],
]);

// --- weekly charge failed ---------------------------------------------------------------------------

it('records a charge-failed bell for the parent only, never the tutor', function () {
    Notification::fake();
    ['lesson' => $lesson, 'tutorUser' => $tutorUser, 'parentUser' => $parentUser] = notifLesson(LessonStatus::Reserved);

    event(new LessonChargeFailed($lesson, now()->addDay()->toImmutable()));

    Notification::assertSentTo($parentUser, WeeklyChargeFailedNotification::class);
    Notification::assertNotSentTo($tutorUser, WeeklyChargeFailedNotification::class);
});

// --- progress report available ----------------------------------------------------------------------

it('records a report-available bell for the parent, and is idempotent against a queue retry', function () {
    ['lesson' => $lesson, 'parentUser' => $parentUser] = notifLesson(LessonStatus::CompletedReported);
    $report = ProgressReport::factory()->create(['lesson_id' => $lesson->id, 'tutor_profile_id' => $lesson->tutor_profile_id]);

    event(new ProgressReportSubmitted($report));
    // Simulating a queue retry of the same job: the listener runs a second time for the same report.
    event(new ProgressReportSubmitted($report));

    $rows = DatabaseNotification::query()
        ->where('notifiable_type', $parentUser->getMorphClass())
        ->where('notifiable_id', $parentUser->getKey())
        ->where('type', ProgressReportAvailableNotification::class)
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->data['report_id'])->toBe($report->id);
});

it('records a distinct report-available bell for a second, different report', function () {
    // Two separate lessons (a report is one-per-lesson, `progress_reports.lesson_id` is unique), same
    // learner/parent, so the guard is proven to key on the report id and not just the parent+type pair.
    ['lesson' => $lessonOne, 'parentUser' => $parentUser] = notifLesson(LessonStatus::CompletedReported);
    $lessonTwo = Lesson::factory()->withStatus(LessonStatus::CompletedReported)->create(['learner_id' => $lessonOne->learner_id]);
    $reportOne = ProgressReport::factory()->create(['lesson_id' => $lessonOne->id, 'tutor_profile_id' => $lessonOne->tutor_profile_id]);
    $reportTwo = ProgressReport::factory()->create(['lesson_id' => $lessonTwo->id, 'tutor_profile_id' => $lessonTwo->tutor_profile_id]);

    event(new ProgressReportSubmitted($reportOne));
    event(new ProgressReportSubmitted($reportTwo));

    $count = DatabaseNotification::query()
        ->where('notifiable_type', $parentUser->getMorphClass())
        ->where('notifiable_id', $parentUser->getKey())
        ->where('type', ProgressReportAvailableNotification::class)
        ->count();

    expect($count)->toBe(2);
});

// --- new message -------------------------------------------------------------------------------------

it('records a new-message bell for the recipient only, never the sender', function () {
    Notification::fake();
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $conversation = Conversation::factory()->create([
        'account_user_id' => $parent->id,
        'tutor_profile_id' => $tutor->id,
    ]);

    event(new MessageSent(1, $conversation->id, $parent->id));

    Notification::assertSentTo($tutor->user, NewMessageNotification::class);
    Notification::assertNotSentTo($parent, NewMessageNotification::class);
});

// --- notification centre: IDOR and portal access ------------------------------------------------------

it('404s reading, marking read, and opening another users notification', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    ['lesson' => $lesson] = notifLesson();
    $owner->notify(new LessonConfirmedNotification($lesson));
    $id = $owner->notifications()->sole()->id;

    actingAs($other)->post("/notifications/{$id}/read")->assertNotFound();
    actingAs($other)->post("/notifications/{$id}/open")->assertNotFound();
});

// R141 fix loop 1 (PR #34 round 1, Finding 1b): the notification id column is a uuid, and without a
// ->whereUuid() route constraint a malformed segment reached the query builder and Postgres rejected the
// literal at the database level (SQLSTATE 22P02), surfacing as an uncaught 500 rather than the 404 this
// codebase's IDOR convention promises. Mirrors the existing ->whereNumber('conversation') precedent.
it('404s a malformed (non-uuid) notification id, on both read and open, instead of a database error', function () {
    $owner = User::factory()->create();

    actingAs($owner)->post('/notifications/not-a-uuid/read')->assertNotFound();
    actingAs($owner)->post('/notifications/not-a-uuid/open')->assertNotFound();
});

it('renders the notification centre for a tutor-portal user', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    ['lesson' => $lesson] = notifLesson();
    $tutor->user->notify(new LessonConfirmedNotification($lesson));

    actingAs($tutor->user)->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('notifications/Index')->has('notifications', 1));
});

it('marks all of the current users notifications read in one request, leaving others untouched', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    ['lesson' => $lesson] = notifLesson();
    $owner->notify(new LessonConfirmedNotification($lesson));
    $owner->notify(new LessonConfirmedNotification($lesson));
    $other->notify(new LessonConfirmedNotification($lesson));

    actingAs($owner)->post('/notifications/read-all')->assertRedirect();

    expect($owner->unreadNotifications()->count())->toBe(0);
    expect($other->unreadNotifications()->count())->toBe(1);
});
