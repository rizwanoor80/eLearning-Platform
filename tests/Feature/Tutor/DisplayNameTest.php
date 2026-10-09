<?php

use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;
use App\Models\User;

/**
 * R188(a): a tutor-chosen display name, shown wherever a parent sees the tutor; the full name stays
 * admin-only; contact details are refused.
 */
function dnPost(TutorProfile $profile, array $extra = []): Illuminate\Testing\TestResponse
{
    return test()->actingAs($profile->user)->post(route('tutor.onboarding.personal'), array_merge([
        'country' => 'AE',
        'phone' => '',
        'timezone' => 'Asia/Dubai',
    ], $extra));
}

function dnTutor(string $fullName = 'Amira Haddad'): TutorProfile
{
    return TutorProfile::factory()->create([
        'status' => TutorProfileStatus::Draft,
        'user_id' => User::factory()->tutor()->create(['name' => $fullName]),
    ]);
}

it('defaults to the first word of the account name', function () {
    $profile = dnTutor('Amira Haddad');

    expect($profile->displayName())->toBe('Amira')->and($profile->defaultDisplayName())->toBe('Amira');
});

it('saves a trimmed display name and uses it instead of the first name', function () {
    $profile = dnTutor();

    dnPost($profile, ['display_name' => '  Miss Amira  '])->assertSessionHasNoErrors();

    expect($profile->fresh()->display_name)->toBe('Miss Amira')
        ->and($profile->fresh()->displayName())->toBe('Miss Amira');
});

it('falls back to the first name when the field is blanked again', function () {
    $profile = dnTutor();
    dnPost($profile, ['display_name' => 'Miss Amira']);

    dnPost($profile, ['display_name' => ''])->assertSessionHasNoErrors();

    expect($profile->fresh()->display_name)->toBeNull()->and($profile->fresh()->displayName())->toBe('Amira');
});

it('refuses a display name that is too short, too long, or carries contact details', function (string $value) {
    $profile = dnTutor();

    dnPost($profile, ['display_name' => $value])->assertSessionHasErrors('display_name');

    expect($profile->fresh()->display_name)->toBeNull();
})->with([
    'one character' => ['A'],
    'thirty-one characters' => [str_repeat('a', 31)],
    'email' => ['amira@example.com'],
    'phone' => ['Amira 0501234567'],
    'link' => ['www.amira-tutor.com'],
]);

it('shows parents the chosen name on the public profile and never the full name', function () {
    $profile = TutorProfile::factory()->bookable()->create([
        'user_id' => User::factory()->tutor()->create(['name' => 'Amira Haddad']),
        'display_name' => 'Miss Amira',
    ]);

    test()->actingAs(User::factory()->create())->get(route('tutors.show', $profile))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tutor.name', 'Miss Amira'));
});

it('sends the onboarding page the saved and the default display name', function () {
    $profile = dnTutor('Amira Haddad');

    test()->actingAs($profile->user)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('personal.display_name', null)->where('personal.default_display_name', 'Amira'));
});
