<?php

use App\Enums\AccountDeletionRequestStatus;
use App\Enums\LessonStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\AccountDeletionRequests\AccountDeletionRequestResource;
use App\Filament\Resources\AccountDeletionRequests\Pages\ListAccountDeletionRequests;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Mail\Account\AccountDeletionDeclinedMail;
use App\Models\AccountDeletionRequest;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * R273 (26a): the Accounts list ("Erase account") and the deletion-request queue ("Erase" / "Decline"). Active
 * admins only; both erase through `EraseAccount`.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('refuses a parent, a tutor and a suspended admin on both pages', function () {
    $inactive = User::factory()->admin()->create(['status' => UserStatus::Suspended]);

    foreach ([ListAccounts::getUrl(), ListAccountDeletionRequests::getUrl()] as $url) {
        test()->actingAs(User::factory()->create())->get($url)->assertForbidden();
        test()->actingAs(User::factory()->tutor()->create())->get($url)->assertForbidden();
        test()->actingAs($inactive)->get($url)->assertForbidden();
        test()->actingAs($this->admin)->get($url)->assertOk();
    }
});

it('lists parents and tutors but never an admin, and offers no create, edit or delete', function () {
    $parent = User::factory()->create();
    $tutor = User::factory()->tutor()->create();

    Livewire::actingAs($this->admin)
        ->test(ListAccounts::class)
        ->assertCanSeeTableRecords([$parent, $tutor])
        ->assertCanNotSeeTableRecords([$this->admin])
        ->filterTable('role', 'tutor')
        ->assertCanSeeTableRecords([$tutor])
        ->assertCanNotSeeTableRecords([$parent]);

    expect(AccountResource::canCreate())->toBeFalse()
        ->and(AccountResource::canEdit($parent))->toBeFalse()
        ->and(AccountResource::canDelete($parent))->toBeFalse()
        ->and(AccountResource::canDeleteAny())->toBeFalse()
        ->and(AccountDeletionRequestResource::canCreate())->toBeFalse()
        ->and(AccountDeletionRequestResource::canEdit(AccountDeletionRequest::factory()->create()))->toBeFalse()
        ->and(AccountDeletionRequestResource::canDeleteAny())->toBeFalse();
});

it('erases a parent from the Accounts list through EraseAccount, naming nobody in the notification', function () {
    $parent = User::factory()->create(['name' => 'Sara Parent']);

    Livewire::actingAs($this->admin)
        ->test(ListAccounts::class)
        ->callTableAction('erase', $parent)
        ->assertNotified('Account erased');

    expect($parent->fresh()->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.anonymized')->count())->toBe(1);
});

it('shows a refusal from the Accounts list and erases nothing', function () {
    $profile = TutorProfile::factory()->approved()->create();
    Lesson::factory()->create(['tutor_profile_id' => $profile->id, 'status' => LessonStatus::Confirmed]);

    Livewire::actingAs($this->admin)
        ->test(ListAccounts::class)
        ->callTableAction('erase', $profile->user)
        ->assertNotified('Not erased');

    expect($profile->user->fresh()->trashed())->toBeFalse();
});

it('shows open requests by default and the open count as the navigation badge to an admin only', function () {
    $open = AccountDeletionRequest::factory()->create();
    $declined = AccountDeletionRequest::factory()->declined()->create();

    expect(AccountDeletionRequestResource::getNavigationBadge())->toBeNull();

    test()->actingAs($this->admin);
    expect(AccountDeletionRequestResource::getNavigationBadge())->toBe('1');

    Livewire::actingAs($this->admin)
        ->test(ListAccountDeletionRequests::class)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$declined])
        ->filterTable('status', 'declined')
        ->assertCanSeeTableRecords([$declined])
        ->assertCanNotSeeTableRecords([$open]);

    test()->actingAs(User::factory()->create());
    expect(AccountDeletionRequestResource::getNavigationBadge())->toBeNull();
});

it('erases from the queue: the request becomes erased and leaves the open list', function () {
    $request = AccountDeletionRequest::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(ListAccountDeletionRequests::class)
        ->callTableAction('erase', $request)
        ->assertNotified('Account erased')
        ->assertCanNotSeeTableRecords([$request]);

    expect($request->fresh()->status)->toBe(AccountDeletionRequestStatus::Erased)
        ->and($request->user()->first()->trashed())->toBeTrue();
});

it('keeps the request open when the erase is refused', function () {
    $profile = TutorProfile::factory()->approved()->create();
    Lesson::factory()->create(['tutor_profile_id' => $profile->id, 'status' => LessonStatus::Confirmed]);
    $request = AccountDeletionRequest::factory()->create(['user_id' => $profile->user_id]);

    Livewire::actingAs($this->admin)
        ->test(ListAccountDeletionRequests::class)
        ->callTableAction('erase', $request)
        ->assertNotified('Not erased')
        ->assertCanSeeTableRecords([$request]);

    expect($request->fresh()->status)->toBe(AccountDeletionRequestStatus::Open);
});

it('declines from the queue with a required note, mailing the user', function () {
    Mail::fake();
    $request = AccountDeletionRequest::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(ListAccountDeletionRequests::class)
        ->callTableAction('decline', $request, ['note' => ''])
        ->assertHasTableActionErrors(['note' => 'required']);
    expect($request->fresh()->status)->toBe(AccountDeletionRequestStatus::Open);

    Livewire::actingAs($this->admin)
        ->test(ListAccountDeletionRequests::class)
        ->callTableAction('decline', $request, ['note' => 'Money is still owed.'])
        ->assertNotified('Request declined')
        ->assertCanNotSeeTableRecords([$request]);

    expect($request->fresh()->status)->toBe(AccountDeletionRequestStatus::Declined)
        ->and($request->fresh()->admin_note)->toBe('Money is still owed.');

    Mail::assertSent(AccountDeletionDeclinedMail::class, fn ($mail) => $mail->hasTo($request->user->email));
});

it('offers Erase and Decline only while a request is open', function () {
    $declined = AccountDeletionRequest::factory()->declined()->create();

    Livewire::actingAs($this->admin)
        ->test(ListAccountDeletionRequests::class)
        ->filterTable('status', 'declined')
        ->assertTableActionHidden('erase', $declined)
        ->assertTableActionHidden('decline', $declined);
});
