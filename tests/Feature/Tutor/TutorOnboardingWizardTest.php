<?php

use App\Models\DocumentType;
use App\Models\TutorDocument;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('starts a fresh tutor on the personal step', function () {
    $tutor = User::factory()->tutor()->create(['phone' => null]);

    $response = $this->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page->component('tutor/Onboarding')->where('step', 'personal'));
});

it('saves the personal step and creates a draft profile', function () {
    $tutor = User::factory()->tutor()->create(['phone' => null]);

    $response = $this->actingAs($tutor)->post(route('tutor.onboarding.personal'), [
        'phone' => '+971500000000',
        'country' => 'AE',
        'timezone' => 'Asia/Dubai',
    ]);

    $response->assertRedirect(route('tutor.onboarding'));
    expect($tutor->fresh()->phone)->toBe('+971500000000')
        ->and(TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail()->country)->toBe('AE');
});

it('rejects an invalid timezone on the personal step', function () {
    $tutor = User::factory()->tutor()->create(['phone' => null]);

    $response = $this->actingAs($tutor)->post(route('tutor.onboarding.personal'), [
        'phone' => '+971500000000',
        'country' => 'AE',
        'timezone' => 'Not/ATimezone',
    ]);

    $response->assertSessionHasErrors('timezone');
});

it('moves to the document step once personal is done (R171: document/CV is the next mandatory step)', function () {
    $tutor = User::factory()->tutor()->create();

    $this->actingAs($tutor)->post(route('tutor.onboarding.personal'), [
        'country' => 'AE',
        'timezone' => 'Asia/Dubai',
    ]);

    $response = $this->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page->component('tutor/Onboarding')->where('step', 'document'));
});

it('rejects a permit expiry date that is not in the future', function () {
    $tutor = User::factory()->tutor()->create();

    $response = $this->actingAs($tutor)->post(route('tutor.onboarding.permit'), [
        'permit_number' => 'PMT-1',
        'permit_expires_at' => now()->subDay()->toDateString(),
    ]);

    $response->assertSessionHasErrors('permit_expires_at');
});

it('moves to the document step with the CV document type once personal is done', function () {
    DocumentType::query()->delete();
    $cv = DocumentType::factory()->create(['code' => DocumentType::CV_CODE, 'sort' => 0]);
    DocumentType::factory()->create(['sort' => 1]);

    $tutor = User::factory()->tutor()->create();
    $this->actingAs($tutor)->post(route('tutor.onboarding.personal'), [
        'country' => 'AE',
        'timezone' => 'Asia/Dubai',
    ]);

    $response = $this->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page->component('tutor/Onboarding')
        ->where('step', 'document')
        ->where('currentDocumentType.id', $cv->id));
});

it('offers no current document type when the CV type itself is inactive (R171: the document step is always the CV type, never a fallback)', function () {
    DocumentType::query()->delete();
    DocumentType::factory()->inactive()->create(['code' => DocumentType::CV_CODE, 'sort' => 0]);
    DocumentType::factory()->create(['sort' => 1]);

    $tutor = User::factory()->tutor()->create();
    $this->actingAs($tutor)->post(route('tutor.onboarding.personal'), [
        'country' => 'AE',
        'timezone' => 'Asia/Dubai',
    ]);

    $response = $this->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page->where('step', 'document')->where('currentDocumentType', null));
});

it('uploads the CV document and satisfies the document mandatory step', function () {
    DocumentType::query()->delete();
    $cv = DocumentType::factory()->create(['code' => DocumentType::CV_CODE, 'sort' => 0]);

    $tutor = User::factory()->tutor()->create();
    $this->actingAs($tutor)->post(route('tutor.onboarding.personal'), [
        'country' => 'AE',
        'timezone' => 'Asia/Dubai',
    ]);

    $response = $this->actingAs($tutor)->post(route('tutor.onboarding.documents'), [
        'document_type_id' => $cv->id,
        'file' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
    ]);

    $response->assertRedirect(route('tutor.onboarding'));
    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    expect(TutorDocument::query()->where('tutor_profile_id', $profile->id)->where('document_type_id', $cv->id)->exists())->toBeTrue();

    // CV-or-LinkedIn is now satisfied, so the mandatory position advances straight
    // to 'agreement' (R171: document types other than CV never gate currentStep()).
    $next = $this->actingAs($tutor)->get(route('tutor.onboarding'));
    $next->assertInertia(fn ($page) => $page->where('step', 'agreement'));
});

it('does not move the mandatory step position once a non-CV document type has an upload (R171: other documents are optional)', function () {
    // Sub-cycle 1b adds the bank/subjects/rate/profile/availability/agreement
    // steps after documents and before 'complete' — see TutorOnboardingBankSubjectsRateTest.php
    // and TutorOnboardingAvailabilityAgreementTest.php for the rest of the chain.
    DocumentType::query()->delete();
    DocumentType::factory()->create(['code' => DocumentType::CV_CODE, 'sort' => 0]);
    $other = DocumentType::factory()->create(['sort' => 1]);

    $tutor = User::factory()->tutor()->create();
    $this->actingAs($tutor)->post(route('tutor.onboarding.personal'), [
        'country' => 'AE',
        'timezone' => 'Asia/Dubai',
    ]);
    $this->actingAs($tutor)->post(route('tutor.onboarding.documents'), [
        'document_type_id' => $other->id,
        'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ]);

    $response = $this->actingAs($tutor)->get(route('tutor.onboarding'));

    // CV-or-LinkedIn is still missing — uploading an unrelated document type does not
    // satisfy it, so the mandatory position stays at 'document', never 'bank'.
    $response->assertInertia(fn ($page) => $page->where('step', 'document'));
});

it('lets a soft-deleted document be replaced without a unique-constraint collision', function () {
    // The wizard itself always advances forward within sub-cycle 1a (no
    // re-upload path yet — that arrives with admin review in 1c), so this
    // proves the partial unique index directly at the model level: deleting
    // the current document for a type and inserting a new one for the same
    // (tutor_profile_id, document_type_id) must not collide, because a plain
    // Eloquent unique index would conflict with the soft-deleted row.
    $profile = TutorProfile::factory()->create();
    $type = DocumentType::factory()->create();

    TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')->create();
    $profile->tutorDocuments()->where('document_type_id', $type->id)->delete();
    TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')->create();

    expect(TutorDocument::query()->where('tutor_profile_id', $profile->id)->where('document_type_id', $type->id)->count())->toBe(1)
        ->and(TutorDocument::withTrashed()->where('tutor_profile_id', $profile->id)->where('document_type_id', $type->id)->count())->toBe(2);
});

it('accepts a document upload before the permit (or even personal) step is done (R171: documents are reachable independent of the mandatory position)', function () {
    DocumentType::query()->delete();
    $cv = DocumentType::factory()->create(['code' => DocumentType::CV_CODE, 'sort' => 0]);
    $tutor = User::factory()->tutor()->create(['phone' => null]);

    $response = $this->actingAs($tutor)->post(route('tutor.onboarding.documents'), [
        'document_type_id' => $cv->id,
        'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ]);

    $response->assertRedirect(route('tutor.onboarding'));
});
