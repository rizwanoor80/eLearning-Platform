<?php

use App\Actions\Account\DeclineAccountDeletionRequest;
use App\Actions\Account\EraseAccount;
use App\Actions\Account\RequestAccountDeletion;
use App\Enums\AccountDeletionRequestStatus;
use App\Enums\LessonStatus;
use App\Enums\UserStatus;
use App\Events\Account\AccountDeletionDeclined;
use App\Events\Account\AccountDeletionRequested;
use App\Exceptions\UserDeletionException;
use App\Listeners\Account\SendAccountDeletionDeclinedMail;
use App\Listeners\Account\SendAccountDeletionRequestedMail;
use App\Mail\Account\AccountDeletionDeclinedMail;
use App\Mail\Account\AccountDeletionRequestedMail;
use App\Models\AccountDeletionRequest;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * R273 (26a): request, erase and decline. Audit rows and logs hold ids, roles and statuses only.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('files a request for a parent, audits ids only and tells the requester by mail to their own address', function () {
    Mail::fake();
    $parent = User::factory()->create(['name' => 'Sara Parent', 'email' => 'sara.parent@example.test']);

    $request = app(RequestAccountDeletion::class)($parent);

    expect($request->status)->toBe(AccountDeletionRequestStatus::Open)
        ->and($request->user_id)->toBe($parent->id)
        ->and($parent->fresh()->trashed())->toBeFalse();

    Mail::assertSent(AccountDeletionRequestedMail::class, fn ($mail) => $mail->hasTo('sara.parent@example.test'));

    $log = AuditLog::query()->where('action', 'account_deletion.requested')->sole();
    expect($log->actor_user_id)->toBe($parent->id)
        ->and($log->after)->toBe(['role' => 'account_owner', 'status' => 'open']);
    expect(json_encode($log->toArray()))->not->toContain('Sara')->not->toContain('example.test');
});

it('files a request for a tutor', function () {
    Mail::fake();
    $tutor = User::factory()->tutor()->create();

    $request = app(RequestAccountDeletion::class)($tutor);

    expect($request->status)->toBe(AccountDeletionRequestStatus::Open);
    Mail::assertSent(AccountDeletionRequestedMail::class, 1);
});

it('refuses a second open request and an admin account', function () {
    Mail::fake();
    $parent = User::factory()->create();
    app(RequestAccountDeletion::class)($parent);

    expect(fn () => app(RequestAccountDeletion::class)($parent))->toThrow(ValidationException::class);
    expect(fn () => app(RequestAccountDeletion::class)($this->admin))->toThrow(ValidationException::class);

    expect(AccountDeletionRequest::query()->count())->toBe(1);
    Mail::assertSent(AccountDeletionRequestedMail::class, 1);
});

it('backs the one-open-request rule with a partial unique index', function () {
    $parent = User::factory()->create();
    AccountDeletionRequest::factory()->create(['user_id' => $parent->id]);

    // A savepoint, so the failed insert does not abort the test's own transaction.
    expect(fn () => DB::transaction(fn () => AccountDeletionRequest::factory()->create(['user_id' => $parent->id])))
        ->toThrow(UniqueConstraintViolationException::class);

    AccountDeletionRequest::factory()->declined()->create(['user_id' => $parent->id]);
    AccountDeletionRequest::factory()->erased()->create(['user_id' => $parent->id]);

    expect(AccountDeletionRequest::query()->where('user_id', $parent->id)->count())->toBe(3);
});

it('allows a new request after a decline', function () {
    Mail::fake();
    $parent = User::factory()->create();
    $first = app(RequestAccountDeletion::class)($parent);
    app(DeclineAccountDeletionRequest::class)($this->admin, $first, 'A lesson is still open.');

    $second = app(RequestAccountDeletion::class)($parent);

    expect($second->id)->not->toBe($first->id)->and($second->status)->toBe(AccountDeletionRequestStatus::Open);
});

it('erases a parent through the request: the account is tombstoned, the request is marked erased and audited', function () {
    Mail::fake();
    $parent = User::factory()->create(['phone' => '+971500000000']);
    Learner::factory()->create(['account_user_id' => $parent->id]);
    $request = app(RequestAccountDeletion::class)($parent);
    Mail::fake();

    app(EraseAccount::class)($this->admin, $parent);

    $parent->refresh();
    $request->refresh();

    expect($parent->trashed())->toBeTrue()
        ->and($parent->name)->toBe('Deleted user')
        ->and($parent->phone)->toBeNull()
        ->and($request->status)->toBe(AccountDeletionRequestStatus::Erased)
        ->and($request->handled_by)->toBe($this->admin->id)
        ->and($request->handled_at)->not->toBeNull();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    $log = AuditLog::query()->where('action', 'account_deletion.erased')->sole();
    expect($log->actor_user_id)->toBe($this->admin->id)
        ->and($log->before)->toBe(['status' => 'open'])
        ->and($log->after)->toBe(['status' => 'erased']);
});

it('erases a tutor with no open request and records no request', function () {
    $profile = TutorProfile::factory()->approved()->create();

    app(EraseAccount::class)($this->admin, $profile->user);

    expect($profile->user->fresh()->trashed())->toBeTrue()
        ->and(AccountDeletionRequest::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'user.anonymized')->count())->toBe(1);
});

it('refuses while a lesson is open and leaves the request open', function () {
    Mail::fake();
    $profile = TutorProfile::factory()->approved()->create();
    $lesson = Lesson::factory()->create(['tutor_profile_id' => $profile->id, 'status' => LessonStatus::Confirmed]);
    $request = app(RequestAccountDeletion::class)($profile->user);

    expect(fn () => app(EraseAccount::class)($this->admin, $profile->user))
        ->toThrow(UserDeletionException::class, "#{$lesson->id}");

    expect($profile->user->fresh()->trashed())->toBeFalse()
        ->and($request->fresh()->status)->toBe(AccountDeletionRequestStatus::Open)
        ->and(AuditLog::query()->where('action', 'account_deletion.erased')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'user.anonymized')->count())->toBe(0);
});

it('refuses an admin target, a non-admin actor, a suspended admin and an account already erased', function () {
    $parent = User::factory()->create();
    $other = User::factory()->admin()->create();
    $suspended = User::factory()->admin()->create(['status' => UserStatus::Suspended]);

    expect(fn () => app(EraseAccount::class)($this->admin, $other))->toThrow(UserDeletionException::class);
    expect(fn () => app(EraseAccount::class)($parent, User::factory()->create()))->toThrow(UserDeletionException::class);
    expect(fn () => app(EraseAccount::class)($suspended, $parent))->toThrow(UserDeletionException::class);

    app(EraseAccount::class)($this->admin, $parent);

    expect(fn () => app(EraseAccount::class)($this->admin, $parent))
        ->toThrow(UserDeletionException::class, 'already been erased');
});

it('nulls the decline notes of an erased account', function () {
    Mail::fake();
    $parent = User::factory()->create();
    $first = app(RequestAccountDeletion::class)($parent);
    app(DeclineAccountDeletionRequest::class)($this->admin, $first, 'Money is still owed to Sara.');
    app(RequestAccountDeletion::class)($parent);

    app(EraseAccount::class)($this->admin, $parent);

    expect($first->fresh()->admin_note)->toBeNull()
        ->and($first->fresh()->status)->toBe(AccountDeletionRequestStatus::Declined);
});

it('declines with a note: the account is untouched, the user is mailed the note, the audit row has no note', function () {
    Mail::fake();
    $parent = User::factory()->create(['email' => 'p@example.test']);
    $request = app(RequestAccountDeletion::class)($parent);

    app(DeclineAccountDeletionRequest::class)($this->admin, $request, '  A lesson is still open.  ');

    $request->refresh();
    expect($request->status)->toBe(AccountDeletionRequestStatus::Declined)
        ->and($request->admin_note)->toBe('A lesson is still open.')
        ->and($request->handled_by)->toBe($this->admin->id)
        ->and($parent->fresh()->trashed())->toBeFalse();

    Mail::assertSent(AccountDeletionDeclinedMail::class, fn ($mail) => $mail->hasTo('p@example.test')
        && str_contains($mail->render(), 'A lesson is still open.'));

    $log = AuditLog::query()->where('action', 'account_deletion.declined')->sole();
    expect(json_encode($log->toArray()))->not->toContain('lesson is still open')->not->toContain('example.test');
});

it('refuses a decline without a note, from a non-admin, from a suspended admin, twice, and for an erased account', function () {
    Mail::fake();
    $parent = User::factory()->create();
    $request = app(RequestAccountDeletion::class)($parent);
    $suspended = User::factory()->admin()->create(['status' => UserStatus::Suspended]);

    expect(fn () => app(DeclineAccountDeletionRequest::class)($this->admin, $request, '   '))->toThrow(ValidationException::class);
    expect(fn () => app(DeclineAccountDeletionRequest::class)($parent, $request, 'No'))->toThrow(AuthorizationException::class);
    expect(fn () => app(DeclineAccountDeletionRequest::class)($suspended, $request, 'No'))->toThrow(AuthorizationException::class);
    expect($request->fresh()->status)->toBe(AccountDeletionRequestStatus::Open);

    app(DeclineAccountDeletionRequest::class)($this->admin, $request, 'First');
    expect(fn () => app(DeclineAccountDeletionRequest::class)($this->admin, $request, 'Second'))->toThrow(ValidationException::class);
    expect($request->fresh()->admin_note)->toBe('First');

    $other = User::factory()->create();
    $otherRequest = app(RequestAccountDeletion::class)($other);
    app(EraseAccount::class)($this->admin, $other);
    $otherRequest->refresh();
    expect(fn () => app(DeclineAccountDeletionRequest::class)($this->admin, $otherRequest, 'Late'))->toThrow(ValidationException::class);
});

it('sends nothing when the account is erased between the request and the mail job', function () {
    Mail::fake();
    $parent = User::factory()->create();
    $request = AccountDeletionRequest::factory()->create(['user_id' => $parent->id]);
    app(EraseAccount::class)($this->admin, $parent);

    (new SendAccountDeletionRequestedMail)->handle(new AccountDeletionRequested($request));
    (new SendAccountDeletionDeclinedMail)->handle(new AccountDeletionDeclined($request));

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('queues the listeners and serialises only the request model into the events', function () {
    expect(new SendAccountDeletionRequestedMail)->toBeInstanceOf(ShouldQueue::class)
        ->and(new SendAccountDeletionDeclinedMail)->toBeInstanceOf(ShouldQueue::class)
        ->and(class_uses(AccountDeletionRequested::class))->toContain(SerializesModels::class)
        ->and(class_uses(AccountDeletionDeclined::class))->toContain(SerializesModels::class);

    Event::fake([AccountDeletionRequested::class]);
    app(RequestAccountDeletion::class)(User::factory()->create());
    Event::assertDispatched(AccountDeletionRequested::class, 1);
});
