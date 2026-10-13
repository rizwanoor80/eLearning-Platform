<?php

use App\Actions\Tutor\RequestDisplayName;
use App\Enums\UserStatus;
use App\Filament\Resources\DisplayNameRequests\DisplayNameRequestResource;
use App\Filament\Resources\DisplayNameRequests\Pages\ListDisplayNameRequests;
use App\Mail\Tutor\DisplayNameRejectedMail;
use App\Models\AuditLog;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * R195 (15c): the admin queue of requested display names. Active admins only; each row is decided by the
 * Approve or Reject action, which carry the name the admin was shown so a request replaced in the meantime is
 * refused instead of decided blind.
 */
function dnqRequest(string $name = 'Ms Amira', string $fullName = 'Amira Haddad'): TutorProfile
{
    $profile = TutorProfile::factory()->approved()->create([
        'user_id' => User::factory()->tutor()->create(['name' => $fullName]),
    ]);

    app(RequestDisplayName::class)($profile->user, $profile, $name);
    AuditLog::query()->delete();

    return $profile->fresh();
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('refuses a parent, a tutor and an inactive admin', function () {
    $inactive = User::factory()->admin()->create(['status' => UserStatus::Suspended]);

    test()->actingAs(User::factory()->create())->get(ListDisplayNameRequests::getUrl())->assertForbidden();
    test()->actingAs(User::factory()->tutor()->create())->get(ListDisplayNameRequests::getUrl())->assertForbidden();
    test()->actingAs($inactive)->get(ListDisplayNameRequests::getUrl())->assertForbidden();
});

it('lists only tutors with a pending request, with the name asked for and what parents see now', function () {
    $asked = dnqRequest('Ms Amira');
    $quiet = TutorProfile::factory()->approved()->create();
    $approvedOnly = TutorProfile::factory()->approved()->create(['display_name' => 'Ms Omar']);

    Livewire::actingAs($this->admin)
        ->test(ListDisplayNameRequests::class)
        ->assertCanSeeTableRecords([$asked])
        ->assertCanNotSeeTableRecords([$quiet, $approvedOnly])
        ->assertSee('Ms Amira')
        ->assertSee('Amira');
});

it('offers no create, edit or delete', function () {
    $profile = dnqRequest();

    expect(DisplayNameRequestResource::canCreate())->toBeFalse()
        ->and(DisplayNameRequestResource::canEdit($profile))->toBeFalse()
        ->and(DisplayNameRequestResource::canDelete($profile))->toBeFalse()
        ->and(DisplayNameRequestResource::canDeleteAny())->toBeFalse();
});

it('shows the pending count as the navigation badge to an admin and nothing to anyone else', function () {
    expect(DisplayNameRequestResource::getNavigationBadge())->toBeNull();

    dnqRequest('Ms Amira');
    dnqRequest('Mr Omar', 'Omar Khan');

    test()->actingAs($this->admin);
    expect(DisplayNameRequestResource::getNavigationBadge())->toBe('2');

    test()->actingAs(User::factory()->create());
    expect(DisplayNameRequestResource::getNavigationBadge())->toBeNull();
});

it('approves a request from the queue: the name goes live, the row leaves, the action is audited', function () {
    $profile = dnqRequest('Ms Amira');

    Livewire::actingAs($this->admin)
        ->test(ListDisplayNameRequests::class)
        ->callTableAction('approve', $profile, ['expected' => 'Ms Amira'])
        ->assertNotified('Display name approved')
        ->assertCanNotSeeTableRecords([$profile]);

    expect($profile->fresh()->display_name)->toBe('Ms Amira')
        ->and($profile->fresh()->display_name_requested)->toBeNull()
        ->and(AuditLog::query()->where('action', 'tutor.display_name_approved')->sole()->actor_user_id)->toBe($this->admin->id);
});

it('fills the shown name into the approve form when the modal opens', function () {
    $profile = dnqRequest('Ms Amira');

    Livewire::actingAs($this->admin)
        ->test(ListDisplayNameRequests::class)
        ->mountTableAction('approve', $profile)
        ->assertTableActionDataSet(['expected' => 'Ms Amira']);
});

it('rejects a request from the queue with a note: the tutor is emailed, parents keep the old name', function () {
    Mail::fake();
    $profile = dnqRequest('Ms Amira');

    Livewire::actingAs($this->admin)
        ->test(ListDisplayNameRequests::class)
        ->callTableAction('reject', $profile, ['expected' => 'Ms Amira', 'note' => 'Please use your own first name.'])
        ->assertNotified('Display name rejected')
        ->assertCanNotSeeTableRecords([$profile]);

    expect($profile->fresh()->display_name)->toBeNull()
        ->and($profile->fresh()->display_name_requested)->toBeNull()
        ->and($profile->fresh()->displayName())->toBe('Amira');

    Mail::assertQueued(DisplayNameRejectedMail::class, fn (DisplayNameRejectedMail $mail) => $mail->hasTo($profile->user->email));

    $log = AuditLog::query()->where('action', 'tutor.display_name_rejected')->sole();
    expect($log->after)->toBe(['display_name_requested' => null, 'note' => 'Please use your own first name.']);
});

it('requires a note to reject', function () {
    $profile = dnqRequest('Ms Amira');

    Livewire::actingAs($this->admin)
        ->test(ListDisplayNameRequests::class)
        ->callTableAction('reject', $profile, ['expected' => 'Ms Amira', 'note' => ''])
        ->assertHasTableActionErrors(['note' => 'required']);

    expect($profile->fresh()->display_name_requested)->toBe('Ms Amira')->and(AuditLog::query()->count())->toBe(0);
});

it('refuses to decide a request the tutor replaced after the admin opened it', function (string $action, array $extra) {
    Mail::fake();
    $profile = dnqRequest('Ms Amira');

    // The tutor swaps the request while the admin has "Ms Amira" on screen.
    app(RequestDisplayName::class)($profile->user, $profile->fresh(), 'Amira H.');
    AuditLog::query()->delete();

    Livewire::actingAs($this->admin)
        ->test(ListDisplayNameRequests::class)
        ->callTableAction($action, $profile, ['expected' => 'Ms Amira'] + $extra)
        ->assertNotified('Not done');

    $fresh = $profile->fresh();

    expect($fresh->display_name)->toBeNull()
        ->and($fresh->display_name_requested)->toBe('Amira H.')
        ->and(AuditLog::query()->count())->toBe(0);

    Mail::assertNothingQueued();
})->with([
    'approve' => ['approve', []],
    'reject' => ['reject', ['note' => 'No.']],
]);

it('says why when a stored request no longer passes the rule, and leaves it for a rejection', function () {
    $profile = TutorProfile::factory()->approved()->create();
    $profile->forceFill(['display_name_requested' => 'Sara 2', 'display_name_requested_at' => now()])->save();

    Livewire::actingAs($this->admin)
        ->test(ListDisplayNameRequests::class)
        ->callTableAction('approve', $profile, ['expected' => 'Sara 2'])
        ->assertNotified('Not done');

    expect($profile->fresh()->display_name)->toBeNull()->and($profile->fresh()->display_name_requested)->toBe('Sara 2');
});
