<?php

use App\Enums\TutorProfileStatus;
use App\Http\Requests\Tutor\Onboarding\PersonalStepRequest;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * R188(a): a tutor-chosen display name, shown wherever a parent sees the tutor; the full name stays
 * admin-only; contact details are refused.
 */
function dnPost(TutorProfile $profile, array $extra = []): TestResponse
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

it('refuses a display name that could hide or fake contact details or read as blank (review 14b item 1, 2)', function (string $value) {
    $profile = dnTutor();

    dnPost($profile, ['display_name' => $value])->assertSessionHasErrors('display_name');

    expect($profile->fresh()->display_name)->toBeNull();
})->with([
    'reversed email behind a direction override' => ["\u{202E}moc.liamg@aras"],
    'social handle' => ['@sara_tutor'],
    'digits' => ['Sara 2'],
    'one letter and a full stop' => ['A.'],
    'one letter and a hyphen' => ['A-'],
    'leading symbol' => ['-Sara'],
    'dot-shaped Lisu tone letter' => ["sarahtutor\u{A4F8}com"],
    'dot-shaped Latin letter' => ["sarahtutor\u{A78F}com"],
    'enclosing mark that draws as an at sign' => ["saraa\u{20DD}gmail\u{A4F8}com"],
    'invisible Mongolian variation selector' => ["sara.sch\u{180B}ool"],
    'invisible Khitan filler' => ["sara.tut\u{16FE4}ors"],
    'stack of combining marks' => ["Sa\u{0336}\u{0336}\u{0336}\u{0336}ra"],
]);

it('refuses a trailing line break on its own, without relying on the middleware trimming it', function () {
    $request = new PersonalStepRequest;
    $rule = new ReflectionClassConstant($request, 'NAME_PATTERN');

    expect(preg_match($rule->getValue(), "Sara\n"))->toBe(0)->and(preg_match($rule->getValue(), 'Sara'))->toBe(1);
});

// Not refused, and not stored: Laravel's TrimStrings/ConvertEmptyStringsToNull treats a name that is only
// invisible characters as blank, so it saves as null and the first-name default shows. A spelled-out address
// ("sara at gmail dot com") is a documented MessageMasker limit and cannot be told from a name made of words.
it('stores a name of only invisible characters as blank, so the default shows', function () {
    $profile = dnTutor('Amira Haddad');

    dnPost($profile, ['display_name' => '‍‍']);

    expect($profile->fresh()->display_name)->toBeNull()->and($profile->fresh()->displayName())->toBe('Amira');
});

it('accepts ordinary names in several scripts', function (string $value) {
    $profile = dnTutor();

    dnPost($profile, ['display_name' => $value])->assertSessionHasNoErrors();

    expect($profile->fresh()->display_name)->toBe($value);
})->with([
    'title and dot' => ['Ms. Amira'],
    'apostrophe and hyphen' => ["Mary-Jane O'Neil"],
    'arabic' => ['أميرة'],
    'persian with joiner' => ["می\u{200C}خواهم"],
    'accents' => ['José Müller'],
    'okina' => ['Keʻala'],
    'stacked Vietnamese accents' => ['Nguyễn Thị'],
    'devanagari' => ['प्रिया शर्मा'],
    'cjk' => ['李明'],
]);

it('never displays an invisible format character from a stored value, and never a blank name', function () {
    $profile = dnTutor('Amira Haddad');

    $profile->forceFill(['display_name' => "\u{202E}moc.liamg@aras"])->save();
    expect($profile->fresh()->displayName())->toBe('moc.liamg@aras');

    $profile->forceFill(['display_name' => "sara.sch\u{180B}ool"])->save();
    expect($profile->fresh()->displayName())->toBe('sara.school');

    $profile->forceFill(['display_name' => "\u{200D}\u{200D}"])->save();
    expect($profile->fresh()->displayName())->toBe('Amira');
});
