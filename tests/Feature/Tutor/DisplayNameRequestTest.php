<?php

use App\Actions\Tutor\RequestDisplayName;
use App\Enums\TutorProfileStatus;
use App\Models\AuditLog;
use App\Models\TutorProfile;
use App\Models\User;
use App\Rules\DisplayNameRule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * R195 (15c): a tutor asks for a display name from Settings. The request is stored, audited and shown to no
 * one but its owner until an admin approves it; the R192 rule runs first.
 */
function dnTutor(string $fullName = 'Amira Haddad', array $profile = []): TutorProfile
{
    return TutorProfile::factory()->approved()->create($profile + [
        'user_id' => User::factory()->tutor()->create(['name' => $fullName]),
    ]);
}

it('adds the three display-name columns to tutor_profiles', function () {
    expect(Schema::hasColumns('tutor_profiles', ['display_name', 'display_name_requested', 'display_name_requested_at']))->toBeTrue();
});

it('stores a request, audits it and changes nothing parents see', function () {
    $profile = dnTutor();

    test()->actingAs($profile->user)->post(route('display-name.store'), ['display_name' => 'Amira H.'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasNoErrors();

    $fresh = $profile->fresh();

    expect($fresh->display_name_requested)->toBe('Amira H.')
        ->and($fresh->display_name_requested_at)->not->toBeNull()
        ->and($fresh->display_name)->toBeNull()
        ->and($fresh->displayName())->toBe('Amira');

    $log = AuditLog::query()->where('action', 'tutor.display_name_requested')->sole();

    expect($log->actor_user_id)->toBe($profile->user_id)
        ->and($log->subject_type)->toBe(TutorProfile::class)
        ->and($log->subject_id)->toBe($profile->id)
        ->and($log->before)->toBe(['display_name_requested' => null])
        ->and($log->after)->toBe(['display_name_requested' => 'Amira H.']);
});

it('folds the name to NFKC before it is checked and stored', function () {
    $profile = dnTutor();

    test()->actingAs($profile->user)->post(route('display-name.store'), ['display_name' => "  R\u{0133}k Smit  "])
        ->assertSessionHasNoErrors();

    expect($profile->fresh()->display_name_requested)->toBe('Rijk Smit');
});

it('lets a tutor still in onboarding ask for a name too', function () {
    $profile = dnTutor('Amira Haddad', ['status' => TutorProfileStatus::Draft]);

    test()->actingAs($profile->user)->post(route('display-name.store'), ['display_name' => 'Ms Amira'])
        ->assertSessionHasNoErrors();

    expect($profile->fresh()->display_name_requested)->toBe('Ms Amira');
});

it('refuses a name the R192 rule refuses, storing and auditing nothing', function (?string $value, string $message) {
    $profile = dnTutor();

    test()->actingAs($profile->user)->from(route('profile.edit'))->post(route('display-name.store'), ['display_name' => $value])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['display_name' => $message]);

    expect($profile->fresh()->display_name_requested)->toBeNull()
        ->and($profile->fresh()->display_name_requested_at)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'email' => ['sara@gmail.com', DisplayNameRule::CHARACTERS_MESSAGE],
    'digits' => ['Sara 2', DisplayNameRule::CHARACTERS_MESSAGE],
    'domain split by a space' => ['Sara. com', DisplayNameRule::CHARACTERS_MESSAGE],
    'two scripts' => ["Ali. \u{0441}\u{043E}\u{043C}", DisplayNameRule::CHARACTERS_MESSAGE],
    'zero-width space' => ["Sara\u{200B}Khan", DisplayNameRule::CHARACTERS_MESSAGE],
    'one letter' => ['A', DisplayNameRule::LENGTH_MESSAGE],
    'thirty-one characters' => [str_repeat('a', 31), DisplayNameRule::LENGTH_MESSAGE],
    'blank' => ['', 'The display name field is required.'],
    'missing' => [null, 'The display name field is required.'],
]);

it('refuses a name equal to the current approved one', function () {
    $profile = dnTutor('Amira Haddad', ['display_name' => 'Ms Amira']);

    test()->actingAs($profile->user)->from(route('profile.edit'))->post(route('display-name.store'), ['display_name' => 'Ms Amira'])
        ->assertSessionHasErrors(['display_name' => 'That is already your display name.']);

    expect($profile->fresh()->display_name_requested)->toBeNull();
});

it('lets a second request replace the first, and the audit row keeps the one it replaced', function () {
    $profile = dnTutor();

    test()->actingAs($profile->user)->post(route('display-name.store'), ['display_name' => 'Ms Amira']);
    test()->actingAs($profile->user)->post(route('display-name.store'), ['display_name' => 'Amira H.']);

    expect($profile->fresh()->display_name_requested)->toBe('Amira H.');

    $logs = AuditLog::query()->where('action', 'tutor.display_name_requested')->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[1]->before)->toBe(['display_name_requested' => 'Ms Amira'])
        ->and($logs[1]->after)->toBe(['display_name_requested' => 'Amira H.']);
});

it('does not touch the approved name while a new request waits', function () {
    $profile = dnTutor('Amira Haddad', ['display_name' => 'Ms Amira']);

    test()->actingAs($profile->user)->post(route('display-name.store'), ['display_name' => 'Amira H.'])
        ->assertSessionHasNoErrors();

    expect($profile->fresh()->display_name)->toBe('Ms Amira')
        ->and($profile->fresh()->displayName())->toBe('Ms Amira')
        ->and($profile->fresh()->display_name_requested)->toBe('Amira H.');
});

it('keeps a request out of mass assignment: the columns are not fillable', function () {
    $profile = dnTutor();

    $profile->fill([
        'display_name' => 'Injected',
        'display_name_requested' => 'Injected',
        'display_name_requested_at' => now(),
    ]);

    expect($profile->display_name)->toBeNull()
        ->and($profile->display_name_requested)->toBeNull()
        ->and($profile->display_name_requested_at)->toBeNull();
});

it('does not let the onboarding or the profile forms set a display name', function () {
    $profile = dnTutor('Amira Haddad', ['status' => TutorProfileStatus::Draft]);

    test()->actingAs($profile->user)->post(route('tutor.onboarding.personal'), [
        'country' => 'AE',
        'phone' => '',
        'timezone' => 'Asia/Dubai',
        'display_name' => 'Injected Name',
        'display_name_requested' => 'Injected Name',
    ])->assertSessionHasNoErrors();

    test()->actingAs($profile->user)->patch(route('profile.update'), [
        'name' => 'Amira Haddad',
        'email' => $profile->user->email,
        'display_name' => 'Injected Name',
    ]);

    $fresh = $profile->fresh();

    expect($fresh->display_name)->toBeNull()
        ->and($fresh->display_name_requested)->toBeNull()
        ->and($fresh->displayName())->toBe('Amira');
});

it('answers a parent and an admin with 403', function () {
    test()->actingAs(User::factory()->create())->post(route('display-name.store'), ['display_name' => 'Ms Amira'])->assertForbidden();
    test()->actingAs(User::factory()->admin()->create())->post(route('display-name.store'), ['display_name' => 'Ms Amira'])->assertForbidden();

    expect(TutorProfile::query()->whereNotNull('display_name_requested')->count())->toBe(0);
});

it('sends a guest to the login page and an unverified tutor to the verify page', function () {
    test()->post(route('display-name.store'), ['display_name' => 'Ms Amira'])->assertRedirect(route('login'));

    $unverified = TutorProfile::factory()->approved()->create([
        'user_id' => User::factory()->tutor()->unverified()->create(),
    ]);
    test()->actingAs($unverified->user)->post(route('display-name.store'), ['display_name' => 'Ms Amira'])
        ->assertRedirect(route('verification.notice'));

    expect(TutorProfile::query()->whereNotNull('display_name_requested')->count())->toBe(0);
});

it('answers a tutor account with no profile with 403, never a 500', function () {
    $tutor = User::factory()->tutor()->create();

    test()->actingAs($tutor)->post(route('display-name.store'), ['display_name' => 'Ms Amira'])->assertForbidden();
});

it('refuses a request on a profile that is not the tutor own', function () {
    $mine = dnTutor();
    $theirs = dnTutor('Omar Khan');

    expect(fn () => app(RequestDisplayName::class)($mine->user, $theirs, 'Ms Omar'))
        ->toThrow(ValidationException::class);

    expect($theirs->fresh()->display_name_requested)->toBeNull();
});

it('throttles requests at 10 an hour per user, each one landing in an admin queue', function () {
    $profile = dnTutor();

    foreach (range(1, 10) as $i) {
        test()->actingAs($profile->user)->post(route('display-name.store'), ['display_name' => 'Amira '.chr(64 + $i)])->assertRedirect();
    }

    test()->actingAs($profile->user)->post(route('display-name.store'), ['display_name' => 'Amira Z'])->assertStatus(429);

    // Another tutor has a budget of their own.
    $other = dnTutor('Omar Khan');
    test()->actingAs($other->user)->post(route('display-name.store'), ['display_name' => 'Omar K'])->assertRedirect();
});

it('shows a tutor their own shown name, approved name and pending request on the settings page', function () {
    $profile = dnTutor('Amira Haddad', ['display_name' => 'Ms Amira']);
    $profile->forceFill(['display_name_requested' => 'Amira H.', 'display_name_requested_at' => now()])->save();

    test()->actingAs($profile->user)->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tutorDisplayName.shown', 'Ms Amira')
            ->where('tutorDisplayName.approved', 'Ms Amira')
            ->where('tutorDisplayName.requested', 'Amira H.'));
});

it('shows a tutor with no name set the first word and nothing pending', function () {
    $profile = dnTutor();

    test()->actingAs($profile->user)->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('tutorDisplayName.shown', 'Amira')
            ->where('tutorDisplayName.approved', null)
            ->where('tutorDisplayName.requested', null));
});

// R195 review round 1, item 2: the settings page reads the profile with a query, so the relation is not loaded
// onto the user that `HandleInertiaRequests` shares with every page as `auth.user`, bank fields included.
it('keeps the tutor profile, and its bank fields, out of the shared auth user on the settings page', function () {
    $profile = dnTutor('Amira Haddad', ['bank_iban' => 'AE070331234567890123456']);

    $response = test()->actingAs($profile->user)->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('auth.user.name')
            ->missing('auth.user.tutor_profile')
            ->has('tutorDisplayName.shown'));

    expect($response->getContent())->not->toContain('AE070331234567890123456');
});

it('sends a parent and an admin no display-name section', function () {
    test()->actingAs(User::factory()->create())->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('tutorDisplayName', null));

    test()->actingAs(User::factory()->admin()->create())->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('tutorDisplayName', null));
});
