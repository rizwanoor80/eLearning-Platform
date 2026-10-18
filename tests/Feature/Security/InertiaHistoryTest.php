<?php

use App\Enums\UserStatus;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Inertia history (CP8 18f, R227)
|--------------------------------------------------------------------------
| Page props are kept in the browser's history state, so on a shared device the back button after a
| sign-out could show the previous user's pages. Every Inertia page is sent with `encryptHistory`, and the
| first page after any sign-out carries `clearHistory`, which discards the key the history was sealed with.
*/

it('encrypts the history of a guest page', function () {
    expect(test()->get(route('login'))->viewData('page'))->toHaveKey('encryptHistory', true);
});

it('encrypts the history of a signed-in page', function () {
    expect(test()->actingAs(User::factory()->create())->get(route('profile.edit'))->viewData('page'))
        ->toHaveKey('encryptHistory', true);
});

it('clears the history on the page after a sign-out', function () {
    $response = test()->actingAs(User::factory()->create())->post(route('logout'));

    $response->assertRedirect('/');
    expect(test()->get('/')->viewData('page'))->toHaveKey('clearHistory', true);
});

it('does not clear the history on an ordinary page', function () {
    expect(test()->get(route('login'))->viewData('page'))->not->toHaveKey('clearHistory');
});

it('clears the history when a suspended session is ended', function () {
    $user = User::factory()->create(['status' => UserStatus::Suspended]);

    test()->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('login'));

    expect(test()->get(route('login'))->viewData('page'))->toHaveKey('clearHistory', true);
});
