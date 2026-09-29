<?php

use App\Actions\Lessons\OpenDispute;
use App\Enums\CurriculumCode;
use App\Enums\DisputeReason;
use App\Enums\LessonStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Mail\Admin\AdminDisputeOpenedMail;
use App\Mail\Lessons\DisputeOpenedMail;
use App\Models\Curriculum;
use App\Models\Dispute;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Notifications\Admin\DisputeOpenedAdminNotification;
use App\Notifications\Lessons\DisputeOpenedNotification;
use App\Services\Ledger\LedgerService;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

/**
 * CP8 (R150/PRD §8, R139): "the tutor is emailed that a dispute was opened (no parent text
 * quoted); every active admin is emailed; both get a database notification." This is the
 * dispute-**opened** set only — mirrors `drSetup()` (DisputeResourceTest.php). The "resolved" half
 * of PRD §8's "Dispute opened / resolved | Both + admin" row, and adding the parent to "opened",
 * are out of this sub-cycle's authorised scope: `docs/CHECKPOINTS.md`'s CP8 acceptance box names
 * no notification at all, and R150's own sentence names only the tutor and admins for "opened" —
 * disclosed as a PRD §8 gap in STATUS §6, not silently built here.
 *
 * @return array{lesson: Lesson, dispute: Dispute, tutorUser: User, parentUser: User}
 */
function dnSetup(string $description = 'Something was wrong.'): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);

    $lesson = Lesson::factory()->withStatus(LessonStatus::Completed)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->copy()->subHours(3),
        'ends_at' => now()->copy()->subHours(2),
        'completed_at' => now()->copy()->subHours(2),
    ]);

    app(LedgerService::class)->hold($lesson);

    $dispute = app(OpenDispute::class)($parent, $lesson->fresh(), DisputeReason::Quality, $description);

    return ['lesson' => $lesson->fresh(), 'dispute' => $dispute, 'tutorUser' => $tutor->user, 'parentUser' => $parent];
}

// ---- mail ----------------------------------------------------------------------------------------

it('emails the tutor and every active admin when a dispute is opened, never the parent', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();

    ['dispute' => $dispute, 'tutorUser' => $tutorUser, 'parentUser' => $parentUser] = dnSetup();

    Mail::assertQueued(DisputeOpenedMail::class, 1);
    Mail::assertQueued(DisputeOpenedMail::class, fn (DisputeOpenedMail $mail): bool => $mail->hasTo($tutorUser->email) && $mail->dispute->is($dispute));
    Mail::assertNotQueued(DisputeOpenedMail::class, fn (DisputeOpenedMail $mail): bool => $mail->hasTo($parentUser->email));

    Mail::assertQueued(AdminDisputeOpenedMail::class, 1);
    Mail::assertQueued(AdminDisputeOpenedMail::class, fn (AdminDisputeOpenedMail $mail): bool => $mail->hasTo($admin->email));
});

it('never emails a suspended admin about a dispute opening', function () {
    Mail::fake();
    $suspendedAdmin = User::factory()->admin()->create(['status' => UserStatus::Suspended]);
    $activeAdmin = User::factory()->admin()->create();

    dnSetup();

    Mail::assertQueued(AdminDisputeOpenedMail::class, 1);
    Mail::assertQueued(AdminDisputeOpenedMail::class, fn (AdminDisputeOpenedMail $mail): bool => $mail->hasTo($activeAdmin->email));
    Mail::assertNotQueued(AdminDisputeOpenedMail::class, fn (AdminDisputeOpenedMail $mail): bool => $mail->hasTo($suspendedAdmin->email));
});

it('never quotes the account holder\'s free-text description in the tutor or admin mail', function () {
    ['dispute' => $dispute] = dnSetup(description: 'A very specific secret complaint about the tutor.');

    expect((new DisputeOpenedMail($dispute))->render())->not->toContain('A very specific secret complaint');
    expect((new AdminDisputeOpenedMail($dispute))->render())->not->toContain('A very specific secret complaint');
});

it('sends no dispute-opened mail for a transition that is not into disputed', function () {
    Mail::fake();
    $lesson = Lesson::factory()->withStatus(LessonStatus::Confirmed)->create([
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addHour(),
    ]);

    LessonStateMachine::transition($lesson, LessonStatus::InProgress);

    Mail::assertNothingQueued();
});

// ---- bell ------------------------------------------------------------------------------------------

it('records a dispute-opened bell for the tutor and every active admin, never the parent', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();

    ['tutorUser' => $tutorUser, 'parentUser' => $parentUser] = dnSetup();

    Notification::assertSentTo($tutorUser, DisputeOpenedNotification::class);
    Notification::assertSentTo($admin, DisputeOpenedAdminNotification::class);
    Notification::assertNotSentTo($parentUser, DisputeOpenedNotification::class);
    Notification::assertNotSentTo($parentUser, DisputeOpenedAdminNotification::class);
});

it('never sends a suspended admin a dispute-opened bell', function () {
    Notification::fake();
    $suspendedAdmin = User::factory()->admin()->create(['status' => UserStatus::Suspended]);
    $activeAdmin = User::factory()->admin()->create();

    dnSetup();

    Notification::assertSentTo($activeAdmin, DisputeOpenedAdminNotification::class);
    Notification::assertNotSentTo($suspendedAdmin, DisputeOpenedAdminNotification::class);
});

it('excludes the free-text description from both bell payloads', function () {
    $admin = User::factory()->admin()->create();

    ['tutorUser' => $tutorUser] = dnSetup(description: 'Another private complaint.');

    $tutorRow = $tutorUser->notifications()->where('type', DisputeOpenedNotification::class)->sole();
    $adminRow = $admin->notifications()->where('type', DisputeOpenedAdminNotification::class)->sole();

    expect($tutorRow->data)->not->toHaveKey('description');
    expect($adminRow->data)->not->toHaveKey('description');
    expect(json_encode($tutorRow->data))->not->toContain('Another private complaint');
    expect(json_encode($adminRow->data))->not->toContain('Another private complaint');
});

// ---- notification centre rendering (NotificationPresenter wiring) ----------------------------------

it('renders a dispute-opened bell in the tutor\'s notification centre, linking to the lesson', function () {
    ['lesson' => $lesson, 'tutorUser' => $tutorUser] = dnSetup();

    actingAs($tutorUser)->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('notifications/Index')
            ->where('notifications.0.url', route('lessons.show', $lesson))
            ->where('notifications.0.message', fn (string $message): bool => str_contains($message, 'dispute')));
});

it('renders a dispute-opened bell in an admin\'s notification centre, linking to the Disputes queue, not the lesson room', function () {
    $admin = User::factory()->admin()->create();

    dnSetup();

    actingAs($admin)->get('/notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('notifications/Index')
            ->where('notifications.0.url', DisputeResource::getUrl('index'))
            ->where('notifications.0.message', fn (string $message): bool => str_contains($message, 'dispute')));
});
