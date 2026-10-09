<?php

use App\Actions\RecordAuditLog;
use App\Actions\Tutor\ApproveTutor;
use App\Actions\Tutor\CompleteTutorOnboarding;
use App\Actions\Tutor\RequestTutorChanges;
use App\Enums\TutorProfileStatus;
use App\Enums\TutorReviewSection;
use App\Filament\Resources\TutorProfiles\Pages\ViewTutorProfile;
use App\Mail\Tutor\TutorChangesRequestedMail;
use App\Models\AuditLog;
use App\Models\AvailabilityRule;
use App\Models\TutorProfile;
use App\Models\User;
use Livewire\Livewire;

/**
 * R185: approval no longer needs an availability window; being listed and booked does; an admin can
 * set the windows on the tutor's behalf (audited); "Request changes" names sections.
 */
function r185Approved(bool $withWindow): TutorProfile
{
    $profile = TutorProfile::factory()->approvable()->approved()->create();

    if (! $withWindow) {
        $profile->availabilityRules()->delete();
    }

    return $profile;
}

it('approves a pending tutor who has set no availability window', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::PendingReview]);
    $profile->availabilityRules()->delete();

    (new ApproveTutor(app(RecordAuditLog::class)))($admin, $profile);

    expect($profile->fresh()->status)->toBe(TutorProfileStatus::Approved);
});

it('keeps an approved tutor with no window out of bookable() and out of search; a window brings them back', function () {
    $without = r185Approved(false);
    $with = r185Approved(true);

    $ids = TutorProfile::query()->bookable()->pluck('id')->all();
    expect($ids)->toContain($with->id)->not->toContain($without->id);

    $listed = [];
    test()->get(route('tutors.index'))->assertOk()
        ->assertInertia(function ($page) use (&$listed) {
            $listed = collect($page->toArray()['props']['tutors'])->pluck('id')->all();
        });
    expect($listed)->not->toContain($without->id);

    AvailabilityRule::factory()->create(['tutor_profile_id' => $without->id]);
    expect(TutorProfile::query()->bookable()->whereKey($without->id)->exists())->toBeTrue();
});

it('never makes an unapproved tutor bookable just because a window exists', function (TutorProfileStatus $status) {
    $profile = TutorProfile::factory()->approvable()->create(['status' => $status]);

    expect($profile->availabilityRules()->exists())->toBeTrue()
        ->and(TutorProfile::query()->bookable()->whereKey($profile->id)->exists())->toBeFalse();
})->with([TutorProfileStatus::Draft, TutorProfileStatus::PendingReview, TutorProfileStatus::ChangesRequested, TutorProfileStatus::Suspended, TutorProfileStatus::Rejected]);

it('shows the add-availability banner only to an approved tutor with no window', function () {
    $without = r185Approved(false);
    $with = r185Approved(true);

    test()->actingAs($without->user)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->where('needsAvailability', true));
    test()->actingAs($with->user)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->where('needsAvailability', false));

    $draft = TutorProfile::factory()->create(['status' => TutorProfileStatus::Draft]);
    test()->actingAs($draft->user)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->where('needsAvailability', false));
});

it("lets an admin set a tutor's windows on their behalf, auditing before and after", function () {
    $admin = User::factory()->admin()->create();
    $profile = r185Approved(false);

    Livewire::actingAs($admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->assertActionVisible('editAvailability')
        ->callAction('editAvailability', data: ['rules' => [
            ['weekday' => 2, 'start_time' => '09:00', 'end_time' => '11:00'],
            ['weekday' => 4, 'start_time' => '16:00', 'end_time' => '18:00'],
        ]])
        ->assertNotified('Availability updated');

    expect($profile->availabilityRules()->count())->toBe(2)
        ->and($profile->availabilityRules()->first()->timezone)->toBe($profile->user->timezone)
        ->and(TutorProfile::query()->bookable()->whereKey($profile->id)->exists())->toBeTrue();

    $log = AuditLog::query()->where('action', 'tutor.availability_edited_by_admin')->where('subject_id', $profile->id)->firstOrFail();
    expect($log->actor_user_id)->toBe($admin->id)
        ->and($log->before['rules'])->toBe([])
        ->and($log->after['rules'])->toHaveCount(2)
        ->and($log->after['rules'][0])->toBe(['weekday' => 2, 'start_time' => '09:00', 'end_time' => '11:00']);
});

it('refuses overlapping or inverted windows from the admin editor, with no change and no audit row', function (array $rules) {
    $admin = User::factory()->admin()->create();
    $profile = r185Approved(true);
    $before = $profile->availabilityRules()->count();

    Livewire::actingAs($admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->callAction('editAvailability', data: ['rules' => $rules])
        ->assertNotified('Not allowed');

    expect($profile->availabilityRules()->count())->toBe($before)
        ->and(AuditLog::query()->where('action', 'tutor.availability_edited_by_admin')->exists())->toBeFalse();
})->with([
    'overlap' => [[['weekday' => 1, 'start_time' => '09:00', 'end_time' => '11:00'], ['weekday' => 1, 'start_time' => '10:00', 'end_time' => '12:00']]],
    'inverted' => [[['weekday' => 1, 'start_time' => '12:00', 'end_time' => '10:00']]],
]);

it('hides the availability editor for a draft, rejected or suspended tutor', function (TutorProfileStatus $status) {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->create(['status' => $status]);

    Livewire::actingAs($admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->assertActionHidden('editAvailability');
})->with([TutorProfileStatus::Draft, TutorProfileStatus::Rejected, TutorProfileStatus::Suspended]);

it('requests changes with sections, stores them de-duplicated in checklist order, and audits them', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);

    (new RequestTutorChanges(app(RecordAuditLog::class)))($admin, $profile, ['availability', TutorReviewSection::Contact, 'availability'], null);

    $fresh = $profile->fresh();
    expect($fresh->status)->toBe(TutorProfileStatus::ChangesRequested)
        ->and($fresh->review_sections)->toBe(['contact', 'availability'])
        ->and($fresh->review_note)->toBeNull();

    $log = AuditLog::query()->where('action', 'tutor.changes_requested')->where('subject_id', $profile->id)->firstOrFail();
    expect($log->after['review_sections'])->toBe(['contact', 'availability']);
});

it('refuses an unknown section, and a request with neither a section nor a note, changing nothing', function (array $sections, ?string $note) {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);

    expect(fn () => (new RequestTutorChanges(app(RecordAuditLog::class)))($admin, $profile, $sections, $note))
        ->toThrow(InvalidArgumentException::class);

    expect($profile->fresh()->status)->toBe(TutorProfileStatus::PendingReview)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'unknown key' => [['bio', 'passport'], null],
    'nothing' => [[], '   '],
]);

it('accepts a note-only request', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);

    (new RequestTutorChanges(app(RecordAuditLog::class)))($admin, $profile, [], '  Please call us.  ');

    expect($profile->fresh())->review_note->toBe('Please call us.')->review_sections->toBeNull();
});

it('takes the request-changes form from the admin page, requiring a section or a note', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);

    Livewire::actingAs($admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->callAction('requestChanges', data: ['sections' => [], 'note' => ''])
        ->assertHasActionErrors();
    expect($profile->fresh()->status)->toBe(TutorProfileStatus::PendingReview);

    Livewire::actingAs($admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->callAction('requestChanges', data: ['sections' => ['rate', 'bio'], 'note' => ''])
        ->assertHasNoActionErrors();
    expect($profile->fresh()->review_sections)->toBe(['rate', 'bio'])->and($profile->fresh()->status)->toBe(TutorProfileStatus::ChangesRequested);
});

it('shows the requested sections to the tutor concerned only, and clears them on resubmission', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::PendingReview]);
    $other = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::ChangesRequested, 'review_sections' => ['bank'], 'review_note' => 'Other tutor note.']);

    (new RequestTutorChanges(app(RecordAuditLog::class)))($admin, $profile, ['rate', 'permit'], 'Fix these.');

    test()->actingAs($profile->user)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('reviewSections', ['permit', 'rate'])->where('reviewNote', 'Fix these.'));
    test()->actingAs($other->user)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('reviewSections', ['bank'])->where('reviewNote', 'Other tutor note.'));

    app(CompleteTutorOnboarding::class)($profile->fresh());

    expect($profile->fresh())->review_sections->toBeNull()->review_note->toBeNull();
});

it('names the requested sections in the changes-requested email', function () {
    $profile = TutorProfile::factory()->create([
        'status' => TutorProfileStatus::ChangesRequested,
        'review_sections' => ['permit', 'availability'],
        'review_note' => 'Scan was blurry.',
    ]);

    $html = (new TutorChangesRequestedMail($profile))->render();

    expect($html)->toContain('Work permit')->toContain('Availability')->toContain('Scan was blurry.')
        ->not->toContain('Bank details');
});

it('lets an approved tutor with no window add one from the banner link, and become bookable', function () {
    $profile = r185Approved(false);

    test()->actingAs($profile->user)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('canEditAvailability', true));

    test()->actingAs($profile->user)->post(route('tutor.onboarding.availability'), [
        'rules' => [['weekday' => 2, 'start_time' => '09:00', 'end_time' => '12:00']],
    ])->assertRedirect(route('tutor.onboarding'));

    expect($profile->availabilityRules()->count())->toBe(1)
        ->and(TutorProfile::query()->bookable()->whereKey($profile->id)->exists())->toBeTrue();
});

it('still refuses every other onboarding step for an approved tutor, and offers no availability edit to a pending one', function () {
    $approved = r185Approved(true);
    test()->actingAs($approved->user)->post(route('tutor.onboarding.permit'), [])->assertStatus(409);

    $pending = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::PendingReview]);
    test()->actingAs($pending->user)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('canEditAvailability', false));
    test()->actingAs($pending->user)->post(route('tutor.onboarding.availability'), [
        'rules' => [['weekday' => 3, 'start_time' => '09:00', 'end_time' => '12:00']],
    ])->assertStatus(409);
});
