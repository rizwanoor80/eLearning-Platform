<?php

use App\Actions\Learner\CreateLearner;
use App\Actions\Learner\CreateSelfLearner;
use App\Actions\Match\CloseMatchRequest;
use App\Enums\MatchRequestStatus;
use App\Events\Match\MatchSuggestionsReady;
use App\Exceptions\MatchRequestException;
use App\Filament\Pages\ManageSettings;
use App\Listeners\Match\SendMatchSuggestionsMail;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\MatchRequest;
use App\Models\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

// ---- the /tutors validation redirect (R30 #12, #17) ---------------------------------------------

it('redirects a failed /tutors validation to /tutors itself, not to the homepage', function () {
    test()->get('/tutors?min_price=abc')
        ->assertRedirect(route('tutors.index'))
        ->assertSessionHasErrors('min_price');

    test()->followingRedirects()->get('/tutors?min_price=abc')
        ->assertInertia(fn ($page) => $page->component('tutors/Index')->has('errors.min_price'));
});

it('answers 404, not 500, for a profile id too large to be an integer (R30 #12)', function () {
    test()->get('/tutors/99999999999999999999')->assertNotFound();
    test()->get('/tutors/9223372036854775808')->assertNotFound();
    test()->get('/tutors/abc')->assertNotFound();
});

// ---- the agreement version error is shown ---------------------------------------------------------

it('sends the stale-version message under the `version` key so the form can show it', function () {
    Page::factory()->create(['slug' => 'tutor_agreement', 'version' => 2]);
    $tutor = agreementReadyTutor();

    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1])
        ->assertSessionHasErrors(['version' => 'The agreement was updated while you were reading it. Please read the new version and accept again.']);

    // Vue: `Onboarding.vue` renders `agreementForm.errors.version` (type-checked by vue-tsc, R20).
    expect(file_get_contents(resource_path('js/pages/tutor/Onboarding.vue')))->toContain('agreementForm.errors.version');
});

// ---- the settings bound -----------------------------------------------------------------------------

it('caps booking_max_days at 90 in the settings editor (R30 #5)', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(ManageSettings::class)
        ->fillForm(['booking_max_days' => 91])
        ->call('save')
        ->assertHasFormErrors(['booking_max_days']);

    Livewire::actingAs($admin)->test(ManageSettings::class)
        ->fillForm(['booking_max_days' => 90])
        ->call('save')
        ->assertHasNoFormErrors();
});

// ---- Lesson status is not mass-assignable (R30 #11) --------------------------------------------------

it('does not let mass assignment set a lesson status', function () {
    $lesson = new Lesson(['status' => 'confirmed', 'tutor_profile_id' => 1]);

    expect($lesson->getAttributes())->toBe(['tutor_profile_id' => 1])
        ->and($lesson->isFillable('status'))->toBeFalse();
});

// ---- CloseMatchRequest ------------------------------------------------------------------------------

it('closes a request inside a transaction and refuses a second close (R30 #9)', function () {
    $admin = User::factory()->admin()->create();
    $request = MatchRequest::factory()->create();

    app(CloseMatchRequest::class)($admin, $request);

    expect($request->fresh()->status)->toBe(MatchRequestStatus::Closed)
        ->and($request->status)->toBe(MatchRequestStatus::Closed) // the caller's instance is refreshed too
        ->and(fn () => app(CloseMatchRequest::class)($admin, $request->fresh()))->toThrow(MatchRequestException::class);
});

it('sees a status changed under it: closing a stale copy of an already-closed request is refused (R30 #9)', function () {
    $admin = User::factory()->admin()->create();
    $request = MatchRequest::factory()->create();
    $stale = MatchRequest::query()->findOrFail($request->id); // still says "open"

    MatchRequest::query()->whereKey($request->id)->update(['status' => MatchRequestStatus::Closed->value]);

    expect(fn () => app(CloseMatchRequest::class)($admin, $stale))->toThrow(MatchRequestException::class);
});

// ---- a request deleted before its mail is sent (R30 #10) ----------------------------------------------

it('discards the suggestions job when its request was deleted, and sends nothing (R30 #10)', function () {
    Mail::fake();
    $request = MatchRequest::factory()->create();
    $event = new MatchSuggestionsReady($request);

    // Pre-condition: a queue worker re-fetches the model on unserialise and cannot find it.
    $payload = serialize($event);
    MatchRequest::query()->whereKey($request->id)->delete();
    expect(fn () => unserialize($payload))->toThrow(ModelNotFoundException::class);

    // The framework drops such a job quietly because the listener says so...
    expect((new ReflectionClass(SendMatchSuggestionsMail::class))->getDefaultProperties()['deleteWhenMissingModels'])->toBeTrue();

    // ...and the sync path (which never serialises) sends nothing either.
    event($event);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

// ---- the self-learner follows the account name (R30 #7, #16) -------------------------------------------

it('syncs the self-learner name on any model save of the user, and only when the name changed (R30 #7)', function () {
    $user = User::factory()->create(['name' => 'Sara Adult']);
    $self = (new CreateSelfLearner)($user);
    $child = (new CreateLearner)($user, ['display_name' => 'Kid', 'year_group' => 'Year 3', 'curriculum_id' => Curriculum::factory()->create()->id]);
    $other = Learner::factory()->selfLearner()->create(); // someone else's

    $self->forceFill(['display_name' => 'Manually changed'])->save();
    $user->forceFill(['phone' => '+971500000001'])->save();                 // not a rename: no sync
    expect($self->fresh()->display_name)->toBe('Manually changed');

    $user->update(['name' => 'Sara Renamed']);                              // a plain model save, no controller

    expect($self->fresh()->display_name)->toBe('Sara Renamed')
        ->and($child->fresh()->display_name)->toBe('Kid')
        ->and($other->fresh()->display_name)->not->toBe('Sara Renamed');
});

it('is a no-op for a user without a self-learner, tutors and admins included (R30 #7, #16)', function () {
    foreach ([User::factory()->create(), User::factory()->tutor()->create(), User::factory()->admin()->create()] as $user) {
        $user->update(['name' => 'Renamed Person']);
    }

    expect(Learner::query()->count())->toBe(0);
});

it('still syncs when the rename comes through the profile page', function () {
    $user = User::factory()->create(['name' => 'Sara Adult']);
    $self = (new CreateSelfLearner)($user);

    test()->actingAs($user)->patch(route('profile.update'), ['name' => 'Sara Profile', 'email' => $user->email])->assertSessionHasNoErrors();

    expect($self->fresh()->display_name)->toBe('Sara Profile');
});

// ---- registration ---------------------------------------------------------------------------------------

it('accepts the adult-student flag the way the checkbox component posts it (value="1")', function () {
    test()->post(route('register.store'), [
        'name' => 'Sara Adult', 'email' => 'sara2@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        'is_adult_student' => '1',
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'sara2@example.com')->firstOrFail()->learners()->count())->toBe(1);

    // The component's default value "on" would be rejected by the boolean rule — hence the explicit value="1".
    auth()->logout();
    test()->post(route('register.store'), [
        'name' => 'Other', 'email' => 'other2@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        'is_adult_student' => 'on',
    ])->assertSessionHasErrors('is_adult_student');

    expect(file_get_contents(resource_path('js/pages/auth/Register.vue')))->toContain('value="1"')->toContain("from '@/components/ui/checkbox'");
});
