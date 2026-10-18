<?php

use App\Actions\Tutor\RejectDisplayName;
use App\Actions\Tutor\RequestDisplayName;
use App\Events\Tutor\DisplayNameRejected;
use App\Listeners\Tutor\SendDisplayNameRejectedMail;
use App\Mail\Tutor\DisplayNameRejectedMail;
use App\Models\AuditLog;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Cycle 18d (R225, the 18a review's items 2 and 8): the display-name notes that were left as Low. A rejection on
 * an anonymised tutor is refused, a rejection mail whose user was anonymised before the queue worker ran is not
 * sent, and the three places that read the profile no longer load it onto the request user.
 */
function dnrTutor(): TutorProfile
{
    return TutorProfile::factory()->approved()->create([
        'user_id' => User::factory()->tutor()->create(['name' => 'Amira Haddad']),
    ]);
}

it('refuses to reject a request whose tutor was anonymised after asking', function () {
    Event::fake([DisplayNameRejected::class]);
    $profile = dnrTutor();
    app(RequestDisplayName::class)($profile->user, $profile, 'Ms Amira');
    $profile->user->delete();
    AuditLog::query()->delete();

    expect(fn () => app(RejectDisplayName::class)(User::factory()->admin()->create(), $profile->fresh(), 'Ms Amira', 'Too formal.'))
        ->toThrow(ValidationException::class, 'closed');

    expect($profile->fresh()->display_name_requested)->toBe('Ms Amira')
        ->and(AuditLog::query()->count())->toBe(0);
    Event::assertNotDispatched(DisplayNameRejected::class);
});

it('sends no rejection mail when the user was anonymised between the rejection and the queued job', function () {
    Mail::fake();
    $profile = dnrTutor();
    // A stale event: the profile still carries the user it loaded before the anonymisation (as on a sync queue).
    $event = new DisplayNameRejected($profile->load('user'), 'Ms Amira', 'Too formal.');

    // The rejection committed; then AnonymizeUser soft-deleted the user (and tombstoned the address) before the worker ran.
    User::query()->whereKey($profile->user_id)->firstOrFail()->delete();

    expect($event->profile->user->trashed())->toBeFalse();

    (new SendDisplayNameRejectedMail)->handle($event);

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('still sends the rejection mail to a live tutor from the listener', function () {
    Mail::fake();
    $profile = dnrTutor();

    (new SendDisplayNameRejectedMail)->handle(new DisplayNameRejected($profile->fresh(), 'Ms Amira', 'Too formal.'));

    Mail::assertQueued(DisplayNameRejectedMail::class, fn (DisplayNameRejectedMail $mail) => $mail->hasTo($profile->user->email));
});

it('does not load the tutor profile onto the request user when a display name is requested', function () {
    $profile = dnrTutor();
    $user = User::query()->findOrFail($profile->user_id);

    test()->actingAs($user)->post(route('display-name.store'), ['display_name' => 'Ms Amira'])
        ->assertRedirect(route('profile.edit'));

    expect($user->relationLoaded('tutorProfile'))->toBeFalse()
        ->and($profile->fresh()->display_name_requested)->toBe('Ms Amira');
});

it('does not load the tutor profile onto the request user when a display name is refused', function () {
    $profile = dnrTutor();
    $user = User::query()->findOrFail($profile->user_id);

    test()->actingAs($user)->post(route('display-name.store'), ['display_name' => ''])
        ->assertSessionHasErrors('display_name');

    expect($user->relationLoaded('tutorProfile'))->toBeFalse();
});

it('does not load the tutor profile onto the request user when the lead time changes', function () {
    $profile = dnrTutor();
    $user = User::query()->findOrFail($profile->user_id);

    test()->actingAs($user)->post(route('tutor.lead-time.update'), ['min_lead_hours' => 12])
        ->assertRedirect(route('tutor.dashboard'));

    expect($user->relationLoaded('tutorProfile'))->toBeFalse()
        ->and($profile->fresh()->min_lead_hours)->toBe(12);
});

it('still refuses a user with no tutor profile on both routes', function () {
    $parent = User::factory()->create();

    test()->actingAs($parent)->post(route('display-name.store'), ['display_name' => 'Ms Amira'])->assertForbidden();
    test()->actingAs($parent)->post(route('tutor.lead-time.update'), ['min_lead_hours' => 12])->assertForbidden();
});
