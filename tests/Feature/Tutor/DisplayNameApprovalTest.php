<?php

use App\Actions\Admin\AnonymizeUser;
use App\Actions\RecordAuditLog;
use App\Actions\Tutor\ApproveDisplayName;
use App\Actions\Tutor\RejectDisplayName;
use App\Actions\Tutor\RequestDisplayName;
use App\Enums\TutorProfileStatus;
use App\Enums\UserStatus;
use App\Events\Tutor\DisplayNameRejected;
use App\Listeners\Tutor\SendDisplayNameRejectedMail;
use App\Mail\Tutor\DisplayNameRejectedMail;
use App\Models\AuditLog;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * R195 (15c): an admin approves or rejects a requested display name. Approval copies it to `display_name`
 * and clears the request; rejection clears the request, leaves the approved name alone and emails the tutor
 * the name and the note. Both are audited, both refuse a request the admin did not read, and only an active
 * admin may do either.
 */
function dnaPending(string $requested = 'Ms Amira', array $profile = []): TutorProfile
{
    $profile = TutorProfile::factory()->approved()->create($profile + [
        'user_id' => User::factory()->tutor()->create(['name' => 'Amira Haddad']),
    ]);

    app(RequestDisplayName::class)($profile->user, $profile, $requested);
    AuditLog::query()->delete();

    return $profile->fresh();
}

it('approves a requested name: copied to display_name, request cleared, audited', function () {
    $admin = User::factory()->admin()->create();
    $profile = dnaPending('Ms Amira');

    app(ApproveDisplayName::class)($admin, $profile, 'Ms Amira');

    $fresh = $profile->fresh();

    expect($fresh->display_name)->toBe('Ms Amira')
        ->and($fresh->display_name_requested)->toBeNull()
        ->and($fresh->display_name_requested_at)->toBeNull()
        ->and($fresh->displayName())->toBe('Ms Amira');

    $log = AuditLog::query()->where('action', 'tutor.display_name_approved')->sole();

    expect($log->actor_user_id)->toBe($admin->id)
        ->and($log->subject_type)->toBe(TutorProfile::class)
        ->and($log->subject_id)->toBe($profile->id)
        ->and($log->before)->toBe(['display_name' => null, 'display_name_requested' => 'Ms Amira'])
        ->and($log->after)->toBe(['display_name' => 'Ms Amira', 'display_name_requested' => null]);
});

it('keeps the replaced approved name in the audit row when a second name is approved', function () {
    $admin = User::factory()->admin()->create();
    $profile = dnaPending('Ms Amira');
    app(ApproveDisplayName::class)($admin, $profile, 'Ms Amira');

    app(RequestDisplayName::class)($profile->user, $profile->fresh(), 'Amira H.');
    app(ApproveDisplayName::class)($admin, $profile->fresh(), 'Amira H.');

    $log = AuditLog::query()->where('action', 'tutor.display_name_approved')->orderByDesc('id')->firstOrFail();

    expect($profile->fresh()->display_name)->toBe('Amira H.')
        ->and($log->before)->toBe(['display_name' => 'Ms Amira', 'display_name_requested' => 'Amira H.']);
});

it('sends no email when a name is approved', function () {
    Mail::fake();
    Event::fake([DisplayNameRejected::class]);
    $profile = dnaPending();

    app(ApproveDisplayName::class)(User::factory()->admin()->create(), $profile, 'Ms Amira');

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
    Event::assertNotDispatched(DisplayNameRejected::class);
});

it('rejects a requested name: request cleared, approved name untouched, audited with the note', function () {
    $admin = User::factory()->admin()->create();
    $profile = dnaPending('Amira H.', ['display_name' => 'Ms Amira']);

    app(RejectDisplayName::class)($admin, $profile, 'Amira H.', 'Please use the name on your documents.');

    $fresh = $profile->fresh();

    expect($fresh->display_name)->toBe('Ms Amira')
        ->and($fresh->display_name_requested)->toBeNull()
        ->and($fresh->display_name_requested_at)->toBeNull()
        ->and($fresh->displayName())->toBe('Ms Amira');

    $log = AuditLog::query()->where('action', 'tutor.display_name_rejected')->sole();

    expect($log->actor_user_id)->toBe($admin->id)
        ->and($log->subject_id)->toBe($profile->id)
        ->and($log->before)->toBe(['display_name_requested' => 'Amira H.'])
        ->and($log->after)->toBe(['display_name_requested' => null, 'note' => 'Please use the name on your documents.']);
});

it('dispatches DisplayNameRejected with the name and the note', function () {
    Event::fake([DisplayNameRejected::class]);
    $profile = dnaPending('Ms Amira');

    app(RejectDisplayName::class)(User::factory()->admin()->create(), $profile, 'Ms Amira', 'Too formal.');

    Event::assertDispatched(DisplayNameRejected::class, fn (DisplayNameRejected $e) => $e->profile->is($profile) && $e->name === 'Ms Amira' && $e->note === 'Too formal.');
});

it('emails the tutor, and only the tutor, the rejected name and the note, through a queued listener and mailable', function () {
    Mail::fake();
    $profile = dnaPending('Ms Amira');

    app(RejectDisplayName::class)(User::factory()->admin()->create(), $profile, 'Ms Amira', 'Too formal for a tutor profile.');

    Mail::assertQueued(DisplayNameRejectedMail::class, 1);
    Mail::assertQueued(DisplayNameRejectedMail::class, function (DisplayNameRejectedMail $mail) use ($profile) {
        $mail->assertSeeInHtml('Ms Amira');
        $mail->assertSeeInHtml('Too formal for a tutor profile.');

        return $mail->hasTo($profile->user->email) && count($mail->to) === 1 && $mail->cc === [] && $mail->bcc === [];
    });
});

it('queues the listener and the mailable, so no mail is built in the request', function () {
    expect(new SendDisplayNameRejectedMail)->toBeInstanceOf(ShouldQueue::class)
        ->and(is_subclass_of(DisplayNameRejectedMail::class, ShouldQueue::class))->toBeTrue();
});

it('escapes the tutor-typed name and the admin note in the rejection email', function () {
    $mail = new DisplayNameRejectedMail(TutorProfile::factory()->approved()->create(), 'Sara <b>K</b>', '<script>alert(1)</script>');

    $html = $mail->render();

    expect($html)->not->toContain('<script>')->and($html)->not->toContain('<b>K</b>');
});

// R195 review round 1, item 15: the mail says what parents keep seeing, which is the approved name when the
// tutor has one and the first name otherwise.
it('tells a tutor whose second request is rejected that parents keep seeing the approved name', function () {
    $profile = dnaPending('Amira H.', ['display_name' => 'Mimi']);

    $html = (new DisplayNameRejectedMail($profile, 'Amira H.', 'Please use the name on your documents.'))->render();

    expect($html)->toContain('Parents keep seeing <strong>Mimi</strong>')->and($html)->not->toContain('first name');
});

it('tells a tutor with no approved name that parents keep seeing the first name', function () {
    $profile = dnaPending('Ms Amira');

    $html = (new DisplayNameRejectedMail($profile, 'Ms Amira', 'Too formal.'))->render();

    expect($html)->toContain('Parents keep seeing <strong>Amira</strong>');
});

it('refuses every non-admin: a parent, a tutor, and a suspended admin', function (callable $actor) {
    $profile = dnaPending('Ms Amira');

    $user = $actor();

    expect(fn () => app(ApproveDisplayName::class)($user, $profile, 'Ms Amira'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RejectDisplayName::class)($user, $profile, 'Ms Amira', 'No'))->toThrow(AuthorizationException::class);

    $fresh = $profile->fresh();

    expect($fresh->display_name)->toBeNull()
        ->and($fresh->display_name_requested)->toBe('Ms Amira')
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'parent' => [fn () => User::factory()->create()],
    'tutor' => [fn () => User::factory()->tutor()->create()],
    'suspended admin' => [fn () => User::factory()->admin()->create(['status' => UserStatus::Suspended])],
]);

it('does not let the tutor who asked approve or reject their own request', function () {
    $profile = dnaPending('Ms Amira');

    expect(fn () => app(ApproveDisplayName::class)($profile->user, $profile, 'Ms Amira'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RejectDisplayName::class)($profile->user, $profile, 'Ms Amira', 'No'))->toThrow(AuthorizationException::class);

    $fresh = $profile->fresh();

    expect($fresh->display_name)->toBeNull()
        ->and($fresh->display_name_requested)->toBe('Ms Amira')
        ->and(AuditLog::query()->count())->toBe(0);
});

it('refuses to approve or reject a name that is not the one the admin was shown', function () {
    $admin = User::factory()->admin()->create();
    $profile = dnaPending('Ms Amira');

    // The tutor replaces the request after the admin opened it.
    app(RequestDisplayName::class)($profile->user, $profile->fresh(), 'Amira H.');
    AuditLog::query()->delete();

    expect(fn () => app(ApproveDisplayName::class)($admin, $profile, 'Ms Amira'))->toThrow(ValidationException::class, 'changed the request')
        ->and(fn () => app(RejectDisplayName::class)($admin, $profile, 'Ms Amira', 'No'))->toThrow(ValidationException::class, 'changed the request');

    $fresh = $profile->fresh();

    expect($fresh->display_name)->toBeNull()
        ->and($fresh->display_name_requested)->toBe('Amira H.')
        ->and(AuditLog::query()->count())->toBe(0);
});

it('refuses to approve or reject when there is no pending request', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->approved()->create();

    expect(fn () => app(ApproveDisplayName::class)($admin, $profile, 'Ms Amira'))->toThrow(ValidationException::class, 'no pending')
        ->and(fn () => app(RejectDisplayName::class)($admin, $profile, 'Ms Amira', 'No'))->toThrow(ValidationException::class, 'no pending')
        ->and(fn () => app(ApproveDisplayName::class)($admin, $profile, ''))->toThrow(ValidationException::class, 'no pending');

    expect($profile->fresh()->display_name)->toBeNull()->and(AuditLog::query()->count())->toBe(0);
});

it('refuses a second approval of the same request, so a double click audits once', function () {
    $admin = User::factory()->admin()->create();
    $profile = dnaPending('Ms Amira');

    app(ApproveDisplayName::class)($admin, $profile, 'Ms Amira');

    expect(fn () => app(ApproveDisplayName::class)($admin, $profile, 'Ms Amira'))->toThrow(ValidationException::class, 'no pending')
        ->and(AuditLog::query()->where('action', 'tutor.display_name_approved')->count())->toBe(1);
});

it('runs the rule again at approval: a request stored under an older rule never reaches display_name', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->approved()->create();
    // Stored by hand, as an older, looser rule would have admitted it.
    $profile->forceFill(['display_name_requested' => 'Sara 2', 'display_name_requested_at' => now()])->save();

    expect(fn () => app(ApproveDisplayName::class)($admin, $profile, 'Sara 2'))
        ->toThrow(ValidationException::class, 'no longer passes the display-name rules');

    $fresh = $profile->fresh();

    expect($fresh->display_name)->toBeNull()
        ->and($fresh->display_name_requested)->toBe('Sara 2')
        ->and(AuditLog::query()->count())->toBe(0);
});

it('still lets an admin reject a request that no longer passes the rule', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->approved()->create();
    $profile->forceFill(['display_name_requested' => 'Sara 2', 'display_name_requested_at' => now()])->save();

    app(RejectDisplayName::class)($admin, $profile, 'Sara 2', 'Digits are not allowed.');

    expect($profile->fresh()->display_name_requested)->toBeNull();
});

it('writes the approval and its audit row in one transaction: no audit row, no approval', function () {
    $admin = User::factory()->admin()->create();
    $profile = dnaPending('Ms Amira');

    $failing = new class extends RecordAuditLog
    {
        public function __invoke(...$arguments): never
        {
            throw new RuntimeException('audit failed');
        }
    };

    expect(fn () => (new ApproveDisplayName($failing))($admin, $profile, 'Ms Amira'))->toThrow(RuntimeException::class);

    $fresh = $profile->fresh();

    expect($fresh->display_name)->toBeNull()->and($fresh->display_name_requested)->toBe('Ms Amira');
});

it('removes an approved name and a pending request when the account is anonymised', function () {
    $admin = User::factory()->admin()->create();
    $profile = dnaPending('Amira H.', ['display_name' => 'Ms Amira']);

    app(AnonymizeUser::class)($admin, $profile->user);

    $fresh = $profile->fresh();

    expect($fresh->display_name)->toBeNull()
        ->and($fresh->display_name_requested)->toBeNull()
        ->and($fresh->display_name_requested_at)->toBeNull()
        ->and($fresh->displayName())->toBe('Deleted');
});

it('clears the columns on a tutor profile that was never approved too', function () {
    $admin = User::factory()->admin()->create();
    $profile = dnaPending('Ms Amira', ['status' => TutorProfileStatus::Draft]);

    app(AnonymizeUser::class)($admin, $profile->user);

    expect($profile->fresh()->display_name_requested)->toBeNull();
});

// R195 review round 1, item 17: the profile is read without a lock, so an approval that commits between that
// read and the anonymising write must not survive. The approval is simulated by an UPDATE fired the moment
// AnonymizeUser hydrates the profile: the in-memory copy still shows a pending request and no approved name,
// which a dirty-checked save would leave exactly as the approval wrote it.
it('clears an approved name committed between the profile read and the anonymising write', function () {
    $admin = User::factory()->admin()->create();
    $profile = dnaPending('Ms Amira', ['status' => TutorProfileStatus::Draft]);
    $approved = false;

    TutorProfile::retrieved(function (TutorProfile $read) use (&$approved) {
        if ($approved) {
            return;
        }

        $approved = true;

        DB::table('tutor_profiles')->where('id', $read->id)->update([
            'display_name' => 'Ms Amira',
            'display_name_requested' => null,
            'display_name_requested_at' => null,
        ]);
    });

    app(AnonymizeUser::class)($admin, $profile->user);

    expect($approved)->toBeTrue()
        ->and($profile->fresh()->display_name)->toBeNull()
        ->and($profile->fresh()->displayName())->toBe('Deleted');
});
