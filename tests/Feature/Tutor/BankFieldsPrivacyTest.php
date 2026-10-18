<?php

use App\Models\TutorProfile;
use App\Models\User;

/**
 * Cycle 18a (R217, cycle 15 §6 finding A): a tutor's own decrypted bank details never travel in page props. The
 * shared `auth.user` serialises whatever relation is loaded on the request user, so the model itself hides the
 * four bank attributes; the one screen that shows bank details sends the masked IBAN only, so the full IBAN is
 * in no response body anywhere in the app.
 */
const BFP_IBAN = 'AE070331234567890123456';
const BFP_BANK = 'Zephyrine National Bank';
const BFP_HOLDER = 'Quillon T Haddad';
const BFP_SWIFT = 'ZPHRAEAA';

function bfpTutor(): TutorProfile
{
    return TutorProfile::factory()->approved()->create([
        'user_id' => User::factory()->tutor()->create(),
        'bank_name' => BFP_BANK,
        'bank_account_name' => BFP_HOLDER,
        'bank_iban' => BFP_IBAN,
        'bank_swift' => BFP_SWIFT,
    ]);
}

function bfpExpectNoBankDetails(string $body): void
{
    expect($body)->not->toContain(BFP_IBAN)
        ->not->toContain(BFP_BANK)
        ->not->toContain(BFP_HOLDER)
        ->not->toContain(BFP_SWIFT);
}

it('keeps the tutor\'s decrypted bank details out of the dashboard page', function () {
    $profile = bfpTutor();

    $response = test()->actingAs($profile->user)->get(route('tutor.dashboard'))->assertOk();

    bfpExpectNoBankDetails($response->getContent());

    $response->assertInertia(fn ($page) => $page->component('tutor/Dashboard')->missing('auth.user.tutor_profile'));
});

it('keeps the full IBAN out of the onboarding page and sends the masked form on the bank step', function () {
    $profile = bfpTutor();

    $response = test()->actingAs($profile->user)->get(route('tutor.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('tutor/Onboarding')
            ->missing('auth.user.tutor_profile')
            ->where('profile.bank_iban_masked', str_repeat('•', strlen(BFP_IBAN) - 4).'3456')
            ->missing('profile.bank_iban'));

    expect($response->getContent())->not->toContain(BFP_IBAN);
});

it('keeps the bank details out of every other page a tutor opens', function () {
    $profile = bfpTutor();
    $profile->user->forceFill(['email_verified_at' => now()])->save();

    foreach ([route('profile.edit'), route('messages.index'), route('notifications.index')] as $url) {
        $response = test()->actingAs($profile->user)->get($url)->assertOk();

        bfpExpectNoBankDetails($response->getContent());
    }
});

it('keeps the bank details out of a parent\'s pages', function () {
    bfpTutor();
    $parent = User::factory()->create();

    foreach ([route('dashboard'), route('profile.edit')] as $url) {
        $response = test()->actingAs($parent)->get($url)->assertOk();

        bfpExpectNoBankDetails($response->getContent());
    }
});

it('does not serialise the bank attributes from the model, with or without the relation loaded on the user', function () {
    $profile = bfpTutor();
    $user = $profile->user->load('tutorProfile');

    foreach ([$profile->fresh()->toArray(), $user->toArray()['tutor_profile']] as $array) {
        expect($array)->not->toHaveKeys(['bank_name', 'bank_account_name', 'bank_iban', 'bank_swift']);
    }

    bfpExpectNoBankDetails($profile->fresh()->toJson());
    bfpExpectNoBankDetails($user->toJson());
});

it('still reads the bank attributes directly, for the masked form and the checklist', function () {
    $profile = bfpTutor()->fresh();

    expect($profile->bank_iban)->toBe(BFP_IBAN)
        ->and($profile->bank_name)->toBe(BFP_BANK)
        ->and($profile->bank_account_name)->toBe(BFP_HOLDER)
        ->and($profile->bank_swift)->toBe(BFP_SWIFT)
        ->and($profile->bankIbanMasked())->toEndWith('3456');
});
