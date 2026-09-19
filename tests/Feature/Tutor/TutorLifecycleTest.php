<?php

use App\Actions\RecordAuditLog;
use App\Actions\Tutor\ApproveTutor;
use App\Actions\Tutor\CompleteTutorOnboarding;
use App\Actions\Tutor\ReinstateTutor;
use App\Actions\Tutor\RequestTutorChanges;
use App\Actions\Tutor\RequireDocumentTypeFromApprovedTutors;
use App\Enums\TutorProfileStatus;
use App\Events\Tutor\TutorChangesRequested;
use App\Exceptions\TutorApprovalBlockedException;
use App\Exceptions\TutorStatusTransitionException;
use App\Filament\Resources\DocumentTypes\Pages\CreateDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\EditDocumentType;
use App\Filament\Resources\TutorProfiles\Pages\ListTutorProfiles;
use App\Filament\Resources\TutorProfiles\Pages\ViewTutorProfile;
use App\Mail\Tutor\TutorChangesRequestedMail;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\PriceBand;
use App\Models\TutorDocument;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function lcApproved(array $attributes = []): TutorProfile
{
    return TutorProfile::factory()->approvable()->approved()->create(array_merge([
        'permit_expires_at' => now()->addYear()->toDateString(),
    ], $attributes));
}

function lcSuspended(array $attributes = []): TutorProfile
{
    return lcApproved(array_merge(['status' => TutorProfileStatus::Suspended, 'review_note' => 'Paused.'], $attributes));
}

// ---- (a) reinstate -------------------------------------------------------------------

it('reinstates a suspended tutor to approved when permit, documents and rate are all fine', function () {
    Event::fake();
    $admin = User::factory()->admin()->create();
    $type = DocumentType::factory()->create(['required' => true, 'active' => true]);
    $profile = lcSuspended();
    TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')->accepted()->create();

    $outcome = (new ReinstateTutor(app(RecordAuditLog::class)))($admin, $profile);

    expect($outcome)->toBe(TutorProfileStatus::Approved)
        ->and($profile->fresh())->status->toBe(TutorProfileStatus::Approved)->review_note->toBeNull()
        ->and(TutorProfile::bookable()->whereKey($profile->id)->exists())->toBeTrue();
    $log = AuditLog::query()->where('action', 'tutor.reinstated')->firstOrFail();
    expect($log->actor_user_id)->toBe($admin->id)->and($log->after['status'])->toBe('approved');
    // Suspension sent no email, and neither does a clean reinstatement.
    Event::assertNotDispatched(TutorChangesRequested::class);
});

it('sends a reinstated tutor back to changes requested, with the reasons and an email, when they are not ready', function (Closure $spoil, string $reason) {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $profile = lcSuspended();
    $spoil($profile);

    $outcome = (new ReinstateTutor(app(RecordAuditLog::class)))($admin, $profile);

    expect($outcome)->toBe(TutorProfileStatus::ChangesRequested)
        ->and($profile->fresh())->status->toBe(TutorProfileStatus::ChangesRequested)
        ->review_note->toContain($reason)
        ->and(TutorProfile::bookable()->whereKey($profile->id)->exists())->toBeFalse();
    Mail::assertQueued(TutorChangesRequestedMail::class, fn ($m) => $m->hasTo($profile->user->email));
    expect(AuditLog::query()->where('action', 'tutor.reinstated')->firstOrFail()->after['status'])->toBe('changes_requested');
})->with([
    'permit expired' => [fn (TutorProfile $p) => $p->forceFill(['permit_expires_at' => now()->subDay()->toDateString()])->save(), 'permit'],
    'a required document is not accepted' => [fn () => DocumentType::factory()->create(['required' => true, 'active' => true]), 'document'],
    'rate is outside the band' => [fn () => PriceBand::query()->update(['min_rate' => 1000, 'max_rate' => 2000]), 'outside the current band'],
]);

it('refuses to reinstate a tutor who is not suspended', function (TutorProfileStatus $status) {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->approvable()->create(['status' => $status]);

    expect(fn () => (new ReinstateTutor(app(RecordAuditLog::class)))($admin, $profile))
        ->toThrow(TutorStatusTransitionException::class);
    expect($profile->fresh()->status)->toBe($status);
})->with([
    'draft' => TutorProfileStatus::Draft,
    'pending review' => TutorProfileStatus::PendingReview,
    'changes requested' => TutorProfileStatus::ChangesRequested,
    'approved' => TutorProfileStatus::Approved,
    'rejected' => TutorProfileStatus::Rejected,
]);

it('offers Reinstate only on a suspended tutor, and Request changes on approved ones too', function () {
    $admin = User::factory()->admin()->create();
    $suspended = lcSuspended();
    $approved = lcApproved();

    Livewire::actingAs($admin)->test(ViewTutorProfile::class, ['record' => $suspended->getRouteKey()])
        ->assertActionVisible('reinstate')->assertActionHidden('requestChanges');
    Livewire::actingAs($admin)->test(ViewTutorProfile::class, ['record' => $approved->getRouteKey()])
        ->assertActionHidden('reinstate')->assertActionVisible('requestChanges')->assertActionVisible('suspend');
});

it('reinstates through the Filament action', function () {
    $admin = User::factory()->admin()->create();
    $profile = lcSuspended();

    Livewire::actingAs($admin)->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->callAction('reinstate');

    expect($profile->fresh()->status)->toBe(TutorProfileStatus::Approved);
});

// ---- bookable(): leaving and returning to approved -----------------------------------

it('removes a tutor from bookable() the moment an admin pulls them back, keeping approved_at as history', function () {
    Event::fake();
    $admin = User::factory()->admin()->create();
    $profile = lcApproved();
    expect(TutorProfile::bookable()->whereKey($profile->id)->exists())->toBeTrue();

    (new RequestTutorChanges(app(RecordAuditLog::class)))($admin, $profile, 'Update your bio.');
    expect(TutorProfile::bookable()->whereKey($profile->id)->exists())->toBeFalse()
        ->and($profile->fresh()->approved_at)->not->toBeNull(); // kept as history
});

// ---- (f) approval-time readiness ------------------------------------------------------

it('blocks approval when the permit has expired since submission, or the rate is out of band', function (Closure $spoil, string $reason) {
    Event::fake();
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->approvable()->create([
        'status' => TutorProfileStatus::PendingReview,
        'permit_expires_at' => now()->addYear()->toDateString(),
    ]);
    $spoil($profile);

    expect(fn () => (new ApproveTutor(app(RecordAuditLog::class)))($admin, $profile))
        ->toThrow(TutorApprovalBlockedException::class, $reason);
    expect($profile->fresh()->status)->toBe(TutorProfileStatus::PendingReview);
})->with([
    'permit expired' => [fn (TutorProfile $p) => $p->forceFill(['permit_expires_at' => now()->subDay()->toDateString()])->save(), 'permit'],
    'permit expires today' => [fn (TutorProfile $p) => $p->forceFill(['permit_expires_at' => now()->toDateString()])->save(), 'permit'],
    'rate above the band' => [fn () => PriceBand::query()->update(['min_rate' => 1000, 'max_rate' => 2000]), 'outside the current band'],
    'rate below the band' => [fn () => PriceBand::query()->update(['min_rate' => 90000, 'max_rate' => 99000]), 'outside the current band'],
    'no rate' => [fn (TutorProfile $p) => $p->forceFill(['hourly_rate' => null])->save(), 'no hourly rate'],
    'no band at all' => [fn () => PriceBand::query()->delete(), 'no single price band'],
]);

it('agrees with the bookable() scope on the permit boundary: yesterday, today and tomorrow', function (int $offsetDays, bool $valid) {
    $profile = TutorProfile::factory()->approved()->create(['permit_expires_at' => now()->addDays($offsetDays)->toDateString()]);

    expect($profile->fresh()->permitIsValid())->toBe($valid)
        ->and(TutorProfile::bookable()->whereKey($profile->id)->exists())->toBe($valid);
})->with([
    'yesterday' => [-1, false],
    'today' => [0, false],
    'tomorrow' => [1, true],
]);

it('says a profile with no permit date has no valid permit', function () {
    expect((new TutorProfile)->permitIsValid())->toBeFalse();
});

// ---- (b) re-vetting: a newly required document type ------------------------------------

it('sends approved tutors without an accepted copy back when a required type is created, and leaves everyone else', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $lacking = lcApproved();
    $suspended = lcSuspended();
    $review = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::PendingReview]);
    $draft = TutorProfile::factory()->create(['status' => TutorProfileStatus::Draft]);

    Livewire::actingAs($admin)->test(CreateDocumentType::class)
        ->fillForm(['code' => 'reference_letter', 'name' => 'Reference letter', 'description' => 'From an employer.', 'required' => true, 'active' => true, 'sort' => 9])
        ->call('create')->assertHasNoFormErrors();

    expect($lacking->fresh())->status->toBe(TutorProfileStatus::ChangesRequested)->review_note->toContain('Reference letter')
        ->and(TutorProfile::bookable()->whereKey($lacking->id)->exists())->toBeFalse();
    Mail::assertQueued(TutorChangesRequestedMail::class, fn ($m) => $m->hasTo($lacking->user->email));
    expect($suspended->fresh()->status)->toBe(TutorProfileStatus::Suspended)
        ->and($review->fresh()->status)->toBe(TutorProfileStatus::PendingReview)
        ->and($draft->fresh()->status)->toBe(TutorProfileStatus::Draft);
});

it('does not move an approved tutor who already has an accepted copy, and does nothing for an inactive or optional type', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $covered = lcApproved();
    $type = DocumentType::factory()->create(['required' => false, 'active' => true, 'name' => 'Reference letter']);
    TutorDocument::factory()->for($covered, 'tutorProfile')->for($type, 'documentType')->accepted()->create();
    $other = lcApproved();

    // Optional type turned required: the tutor with an accepted copy stays, the other moves.
    Livewire::actingAs($admin)->test(EditDocumentType::class, ['record' => $type->getRouteKey()])
        ->fillForm(['required' => true])->call('save')->assertHasNoFormErrors();

    expect($covered->fresh()->status)->toBe(TutorProfileStatus::Approved)
        ->and($other->fresh()->status)->toBe(TutorProfileStatus::ChangesRequested);

    // An inactive required type, and a required type turned off, move nobody.
    $stillApproved = lcApproved();
    Livewire::actingAs($admin)->test(CreateDocumentType::class)
        ->fillForm(['code' => 'dormant', 'name' => 'Dormant', 'description' => 'x', 'required' => true, 'active' => false, 'sort' => 1])
        ->call('create')->assertHasNoFormErrors();
    Livewire::actingAs($admin)->test(EditDocumentType::class, ['record' => $type->getRouteKey()])
        ->fillForm(['required' => false])->call('save')->assertHasNoFormErrors();
    expect($stillApproved->fresh()->status)->toBe(TutorProfileStatus::Approved);
});

it('moves tutors when an existing type is switched to active, and a second save moves nobody again', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $type = DocumentType::factory()->create(['required' => true, 'active' => false, 'name' => 'Reference letter']);
    $tutor = lcApproved();

    Livewire::actingAs($admin)->test(EditDocumentType::class, ['record' => $type->getRouteKey()])
        ->fillForm(['active' => true])->call('save')->assertHasNoFormErrors();
    expect($tutor->fresh()->status)->toBe(TutorProfileStatus::ChangesRequested);

    $changesBefore = AuditLog::query()->where('action', 'tutor.changes_requested')->count();
    Livewire::actingAs($admin)->test(EditDocumentType::class, ['record' => $type->getRouteKey()])
        ->fillForm(['name' => 'Reference letter (renamed)'])->call('save')->assertHasNoFormErrors();
    expect(AuditLog::query()->where('action', 'tutor.changes_requested')->count())->toBe($changesBefore);
});

it('sends a moved tutor to the document step in the wizard', function () {
    Mail::fake();
    $admin = User::factory()->admin()->create();
    $tutor = lcApproved();
    $type = DocumentType::factory()->create(['required' => true, 'active' => true, 'name' => 'Reference letter']);
    (new RequireDocumentTypeFromApprovedTutors(app(RequestTutorChanges::class)))($admin, $type);

    $this->actingAs($tutor->user)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('step', 'document')->where('currentDocumentType.id', $type->id));
});

// ---- (c) resubmit ---------------------------------------------------------------------

it('clears the review note, stamps submitted_at and audits when a tutor resubmits', function () {
    Event::fake();
    $profile = TutorProfile::factory()->create([
        'status' => TutorProfileStatus::ChangesRequested,
        'review_note' => 'Please re-upload your permit scan.',
        'submitted_at' => now()->subDays(10),
    ]);

    (new CompleteTutorOnboarding(app(RecordAuditLog::class)))($profile);

    expect($profile->fresh())
        ->status->toBe(TutorProfileStatus::PendingReview)
        ->review_note->toBeNull()
        ->and($profile->fresh()->submitted_at->isToday())->toBeTrue();
    $log = AuditLog::query()->where('action', 'tutor.submitted')->firstOrFail();
    expect($log->actor_user_id)->toBe($profile->user_id)->and($log->before['review_note'])->toBe('Please re-upload your permit scan.');
});

it('orders the approval queue by when a tutor last submitted, not when the profile was created', function () {
    $admin = User::factory()->admin()->create();
    $resubmittedRecently = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview, 'created_at' => now()->subYear(), 'submitted_at' => now()->subHour()]);
    $waitingLonger = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview, 'created_at' => now()->subDay(), 'submitted_at' => now()->subDays(5)]);

    Livewire::actingAs($admin)->test(ListTutorProfiles::class)
        ->assertCanSeeTableRecords([$waitingLonger, $resubmittedRecently], inOrder: true)
        ->sortTable('submitted_at', 'desc')
        ->assertCanSeeTableRecords([$resubmittedRecently, $waitingLonger], inOrder: true);
});

it('backfills submitted_at from created_at for profiles already past draft', function () {
    $migration = require database_path('migrations/2026_09_20_110000_add_submitted_at_to_tutor_profiles.php');
    $submitted = TutorProfile::factory()->create(['status' => TutorProfileStatus::Approved, 'created_at' => '2026-03-01 10:00:00']);
    $draft = TutorProfile::factory()->create(['status' => TutorProfileStatus::Draft]);
    TutorProfile::query()->update(['submitted_at' => null]);

    // Re-run only the backfill statement of the migration's up(): drop and re-add the column.
    Schema::table('tutor_profiles', fn ($table) => $table->dropColumn('submitted_at'));
    $migration->up();

    expect($submitted->fresh()->submitted_at->toDateTimeString())->toBe('2026-03-01 10:00:00')
        ->and($draft->fresh()->submitted_at)->toBeNull();
});
