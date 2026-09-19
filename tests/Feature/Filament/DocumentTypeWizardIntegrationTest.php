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
 * CP1 box 6: adding a required document type in Filament adds a wizard step
 * and blocks approval until it is accepted; setting it inactive removes both
 * — no code change.
 */
it('adds and removes a wizard step and the approval block purely through the Filament resource', function () {
    Event::fake();
    DocumentType::query()->delete();
    $admin = User::factory()->admin()->create();
    $tutor = User::factory()->tutor()->create();
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
        ->assertInertia(fn ($page) => $page->where('step', 'bank'));

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

    // The wizard now has a step for it, and approval is blocked.
    test()->actingAs($tutor)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('step', 'document')->where('currentDocumentType.id', $type->id));
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
        ->assertInertia(fn ($page) => $page->where('step', 'bank'));
    $profile->forceFill(['status' => TutorProfileStatus::PendingReview])->save();
    TutorProfileFactory::makeApprovable($profile);
    (new ApproveTutor(app(RecordAuditLog::class)))($admin, $profile->fresh());
    expect($profile->fresh()->status)->toBe(TutorProfileStatus::Approved);
});
