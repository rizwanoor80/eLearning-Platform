<?php

use App\Enums\TutorDocumentStatus;
use App\Enums\TutorProfileStatus;
use App\Filament\Resources\TutorProfiles\Pages\ListTutorProfiles;
use App\Filament\Resources\TutorProfiles\Pages\ViewTutorProfile;
use App\Filament\Resources\TutorProfiles\RelationManagers\TutorDocumentsRelationManager;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\TutorDocument;
use App\Models\TutorProfile;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('lists pending_review tutors by default for an admin', function () {
    $pending = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);
    TutorProfile::factory()->approved()->create();

    Livewire::actingAs($this->admin)
        ->test(ListTutorProfiles::class)
        ->assertCanSeeTableRecords([$pending]);
});

it('refuses a non-admin access to the tutor approvals resource', function () {
    $tutor = User::factory()->tutor()->create();

    $response = test()->actingAs($tutor)->get(ListTutorProfiles::getUrl());

    $response->assertForbidden();
});

it('approves a tutor with all required documents accepted', function () {
    $requiredType = DocumentType::factory()->create(['required' => true, 'active' => true]);
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);
    TutorDocument::factory()->for($profile, 'tutorProfile')->for($requiredType, 'documentType')->accepted()->create();

    Livewire::actingAs($this->admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->callAction('approve');

    expect($profile->fresh()->status)->toBe(TutorProfileStatus::Approved);
});

it('does not approve a tutor while a required document is unaccepted', function () {
    DocumentType::factory()->create(['required' => true, 'active' => true]);
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);

    Livewire::actingAs($this->admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->callAction('approve');

    expect($profile->fresh()->status)->toBe(TutorProfileStatus::PendingReview);
});

it('rejects a tutor with a note via the view page', function () {
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);

    Livewire::actingAs($this->admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->callAction('reject', data: ['note' => 'Incomplete profile.']);

    expect($profile->fresh())
        ->status->toBe(TutorProfileStatus::Rejected)
        ->review_note->toBe('Incomplete profile.');
});

it('requests changes with a note via the view page', function () {
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);

    Livewire::actingAs($this->admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->callAction('requestChanges', data: ['note' => 'Fix your bio.']);

    expect($profile->fresh())
        ->status->toBe(TutorProfileStatus::ChangesRequested)
        ->review_note->toBe('Fix your bio.');
});

it('only offers suspend on an approved tutor', function () {
    $pending = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);
    $approved = TutorProfile::factory()->approved()->create();

    Livewire::actingAs($this->admin)
        ->test(ViewTutorProfile::class, ['record' => $pending->getRouteKey()])
        ->assertActionHidden('suspend');

    Livewire::actingAs($this->admin)
        ->test(ViewTutorProfile::class, ['record' => $approved->getRouteKey()])
        ->assertActionVisible('suspend')
        ->callAction('suspend', data: ['note' => 'Multiple late cancellations.']);

    expect($approved->fresh()->status)->toBe(TutorProfileStatus::Suspended);
});

it('lets an admin accept a document from the relation manager', function () {
    $profile = TutorProfile::factory()->create();
    $document = TutorDocument::factory()->for($profile, 'tutorProfile')->create();

    Livewire::actingAs($this->admin)
        ->test(TutorDocumentsRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => ViewTutorProfile::class,
        ])
        ->callTableAction('accept', $document);

    expect($document->fresh()->status)->toBe(TutorDocumentStatus::Accepted)
        ->and(AuditLog::query()->where('action', 'tutor_document.accepted')->exists())->toBeTrue();
});

it('hides accept and reject on documents of an approved tutor (R31)', function () {
    $profile = TutorProfile::factory()->approved()->create();
    $document = TutorDocument::factory()->for($profile, 'tutorProfile')->create();

    Livewire::actingAs($this->admin)
        ->test(TutorDocumentsRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => ViewTutorProfile::class,
        ])
        ->assertTableActionHidden('accept', $document)
        ->assertTableActionHidden('reject', $document)
        ->assertTableActionVisible('view', $document);
});

it('shows accept and reject on documents of a pending-review tutor (R31)', function () {
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);
    $document = TutorDocument::factory()->for($profile, 'tutorProfile')->create();

    Livewire::actingAs($this->admin)
        ->test(TutorDocumentsRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => ViewTutorProfile::class,
        ])
        ->assertTableActionVisible('accept', $document)
        ->assertTableActionVisible('reject', $document);
});
