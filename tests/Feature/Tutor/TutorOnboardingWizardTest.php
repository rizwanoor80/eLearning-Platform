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
        'timezone' => 'Asia/Dubai',
    ]);

    $response->assertRedirect(route('tutor.onboarding'));
    expect($tutor->fresh()->phone)->toBe('+971500000000')
        ->and(TutorProfile::query()->where('user_id', $tutor->id)->exists())->toBeTrue();
});

it('rejects an invalid timezone on the personal step', function () {
    $tutor = User::factory()->tutor()->create(['phone' => null]);

    $response = $this->actingAs($tutor)->post(route('tutor.onboarding.personal'), [
        'phone' => '+971500000000',
        'timezone' => 'Not/ATimezone',
    ]);

    $response->assertSessionHasErrors('timezone');
});

it('moves to the permit step once personal is done', function () {
    $tutor = User::factory()->tutor()->create();

    $response = $this->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page->component('tutor/Onboarding')->where('step', 'permit'));
});

it('rejects a permit expiry date that is not in the future', function () {
    $tutor = User::factory()->tutor()->create();

    $response = $this->actingAs($tutor)->post(route('tutor.onboarding.permit'), [
        'permit_number' => 'PMT-1',
        'permit_expires_at' => now()->subDay()->toDateString(),
    ]);

    $response->assertSessionHasErrors('permit_expires_at');
});

it('moves to the first active document type once permit is saved', function () {
    DocumentType::query()->delete();
    $first = DocumentType::factory()->create(['sort' => 0]);
    DocumentType::factory()->create(['sort' => 1]);

    $tutor = User::factory()->tutor()->create();
    $this->actingAs($tutor)->post(route('tutor.onboarding.permit'), [
        'permit_number' => 'PMT-1',
        'permit_expires_at' => now()->addYear()->toDateString(),
    ]);

    $response = $this->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page->component('tutor/Onboarding')
        ->where('step', 'document')
        ->where('currentDocumentType.id', $first->id));
});

it('skips inactive document types entirely', function () {
    DocumentType::query()->delete();
    DocumentType::factory()->inactive()->create(['sort' => 0]);
    $active = DocumentType::factory()->create(['sort' => 1]);

    $tutor = User::factory()->tutor()->create();
    $this->actingAs($tutor)->post(route('tutor.onboarding.permit'), [
        'permit_number' => 'PMT-1',
        'permit_expires_at' => now()->addYear()->toDateString(),
    ]);

    $response = $this->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page->where('currentDocumentType.id', $active->id));
});

it('uploads a document and advances to the next document type', function () {
    DocumentType::query()->delete();
    $first = DocumentType::factory()->create(['sort' => 0]);
    $second = DocumentType::factory()->create(['sort' => 1]);

    $tutor = User::factory()->tutor()->create();
    $this->actingAs($tutor)->post(route('tutor.onboarding.permit'), [
        'permit_number' => 'PMT-1',
        'permit_expires_at' => now()->addYear()->toDateString(),
    ]);

    $response = $this->actingAs($tutor)->post(route('tutor.onboarding.documents'), [
        'file' => UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf'),
    ]);

    $response->assertRedirect(route('tutor.onboarding'));
    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    expect(TutorDocument::query()->where('tutor_profile_id', $profile->id)->where('document_type_id', $first->id)->exists())->toBeTrue();

    $next = $this->actingAs($tutor)->get(route('tutor.onboarding'));
    $next->assertInertia(fn ($page) => $page->where('step', 'document')->where('currentDocumentType.id', $second->id));
});

it('reaches the complete step once every active document type has an upload', function () {
    DocumentType::query()->delete();
    $only = DocumentType::factory()->create(['sort' => 0]);

    $tutor = User::factory()->tutor()->create();
    $this->actingAs($tutor)->post(route('tutor.onboarding.permit'), [
        'permit_number' => 'PMT-1',
        'permit_expires_at' => now()->addYear()->toDateString(),
    ]);
    $this->actingAs($tutor)->post(route('tutor.onboarding.documents'), [
        'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ]);

    $response = $this->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page->where('step', 'complete'));
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

it('refuses to accept a document upload before the permit step is done', function () {
    $tutor = User::factory()->tutor()->create(['phone' => null]);

    $response = $this->actingAs($tutor)->post(route('tutor.onboarding.documents'), [
        'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ]);

    $response->assertStatus(409);
});
