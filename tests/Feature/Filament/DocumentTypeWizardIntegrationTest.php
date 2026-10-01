<?php

use App\Actions\RecordAuditLog;
use App\Actions\Tutor\ApproveTutor;
use App\Enums\TutorProfileStatus;
use App\Exceptions\TutorApprovalBlockedException;
use App\Filament\Resources\DocumentTypes\Pages\CreateDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\EditDocumentType;
use App\Models\DocumentType;
use App\Models\TutorProfile;
use App\Models\User;
use Database\Factories\TutorProfileFactory;
use Livewire\Livewire;

/**
 * CP1 box 6: adding a required document type in Filament makes it visible to
 * upload and blocks approval until it is accepted; setting it inactive removes
 * both — no code change.
 *
 * R171: a non-CV required type never becomes the wizard's mandatory 'document'
 * step — that gate only ever looks at CV-or-LinkedIn (`currentStep()` always
 * resolves the CV_CODE type specifically). So this proves the Filament-added
 * type shows up in `documentTypes` for the tutor to act on, and still blocks
 * `hasAllRequiredDocumentsAccepted()` / `ApproveTutor`, while the wizard's own
 * step position is driven by CV-or-LinkedIn as usual.
 */
it('adds and removes an approval requirement purely through the Filament resource', function () {
    Event::fake();
    DocumentType::query()->delete();
    $admin = User::factory()->admin()->create();
    $tutor = User::factory()->tutor()->create();
    test()->actingAs($tutor)->post(route('tutor.onboarding.personal'), [
        'country' => 'AE',
        'timezone' => 'Asia/Dubai',
    ]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.linkedin'), [
        'linkedin_url' => 'https://www.linkedin.com/in/test-tutor',
    ]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.permit'), [
        'permit_number' => 'PMT-1',
        'permit_expires_at' => now()->addYear()->toDateString(),
    ]);
    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    $profile->forceFill(['status' => TutorProfileStatus::PendingReview])->save();

    // No document types yet: nothing to upload and nothing blocking approval.
    expect($profile->hasAllRequiredDocumentsAccepted())->toBeTrue();
    $profile->forceFill(['status' => TutorProfileStatus::Draft])->save();
    test()->actingAs($tutor)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('step', 'agreement')->has('documentTypes', 0));

    // Admin adds a required, active type through Filament.
    Livewire::actingAs($admin)->test(CreateDocumentType::class)
        ->fillForm([
            'code' => 'reference_letter',
            'name' => 'Reference letter',
            'description' => 'A reference from a previous employer.',
            'required' => true,
            'active' => true,
            'sort' => 9,
        ])
        ->call('create')
        ->assertHasNoFormErrors();
    $type = DocumentType::query()->where('code', 'reference_letter')->firstOrFail();

    // The tutor can now see it to upload, and approval is blocked — but the
    // wizard's mandatory step position is unaffected (CV-or-LinkedIn is still
    // satisfied, so it stays at 'agreement').
    test()->actingAs($tutor)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('step', 'agreement')->has('documentTypes', 1)->where('documentTypes.0.id', $type->id));
    $profile->forceFill(['status' => TutorProfileStatus::PendingReview])->save();
    expect($profile->fresh()->hasAllRequiredDocumentsAccepted())->toBeFalse()
        ->and(fn () => (new ApproveTutor(app(RecordAuditLog::class)))($admin, $profile->fresh()))
        ->toThrow(TutorApprovalBlockedException::class);

    // Admin deactivates it through Filament: both go away, no code change.
    Livewire::actingAs($admin)->test(EditDocumentType::class, ['record' => $type->getRouteKey()])
        ->fillForm(['active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $profile->forceFill(['status' => TutorProfileStatus::Draft])->save();
    test()->actingAs($tutor)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('step', 'agreement')->has('documentTypes', 0));
    $profile->forceFill(['status' => TutorProfileStatus::PendingReview])->save();
    TutorProfileFactory::makeApprovable($profile);
    (new ApproveTutor(app(RecordAuditLog::class)))($admin, $profile->fresh());
    expect($profile->fresh()->status)->toBe(TutorProfileStatus::Approved);
});
