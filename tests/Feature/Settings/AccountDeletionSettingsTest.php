<?php

use App\Enums\AccountDeletionRequestStatus;
use App\Mail\Account\AccountDeletionRequestedMail;
use App\Models\AccountDeletionRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * R273 (26a): "Request account deletion" in Settings. It never erases; it files a request and says so.
 */
it('shows no request on the settings page at first', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('accountDeletion.status', null)
            ->where('accountDeletion.note', null));
});

it('files a request from settings, acknowledges it and touches nothing on the account', function () {
    Mail::fake();
    $user = User::factory()->create(['email' => 'sara.parent@example.test']);

    $this->actingAs($user)
        ->post(route('account-deletion.store'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->trashed())->toBeFalse()
        ->and(AccountDeletionRequest::query()->where('user_id', $user->id)->sole()->status)->toBe(AccountDeletionRequestStatus::Open);

    Mail::assertSent(AccountDeletionRequestedMail::class, fn ($mail) => $mail->hasTo('sara.parent@example.test'));
    expect(AuditLog::query()->where('action', 'account_deletion.requested')->count())->toBe(1);

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('accountDeletion.status', 'open')->where('accountDeletion.note', null));
});

it('lets a tutor file one', function () {
    Mail::fake();
    $tutor = User::factory()->tutor()->create();

    $this->actingAs($tutor)->post(route('account-deletion.store'))->assertRedirect(route('profile.edit'));

    expect(AccountDeletionRequest::query()->where('user_id', $tutor->id)->count())->toBe(1);
});

it('refuses a second open request without sending a second mail', function () {
    Mail::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('account-deletion.store'));
    $this->actingAs($user)->post(route('account-deletion.store'))->assertSessionHasErrors('account');

    expect(AccountDeletionRequest::query()->count())->toBe(1);
    Mail::assertSent(AccountDeletionRequestedMail::class, 1);
});

it('shows the admin note after a decline and allows a new request', function () {
    Mail::fake();
    $user = User::factory()->create();
    AccountDeletionRequest::factory()->declined()->create(['user_id' => $user->id, 'admin_note' => 'A lesson is still open.']);

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('accountDeletion.status', 'declined')
            ->where('accountDeletion.note', 'A lesson is still open.'));

    $this->actingAs($user)->post(route('account-deletion.store'))->assertSessionHasNoErrors();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('accountDeletion.status', 'open')->where('accountDeletion.note', null));
});

it('gives an admin no section and refuses an admin request, and a guest is sent to log in', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('accountDeletion', null));

    $this->actingAs($admin)->post(route('account-deletion.store'))->assertSessionHasErrors('account');
    expect(AccountDeletionRequest::query()->count())->toBe(0);

    auth()->logout();
    $this->post(route('account-deletion.store'))->assertRedirect(route('login'));
});
