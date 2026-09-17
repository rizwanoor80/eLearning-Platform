<?php

use App\Models\DocumentType;
use App\Models\TutorDocument;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('local');
});

function createOwnedTutorDocument(): TutorDocument
{
    $profile = TutorProfile::factory()->create();
    $document = TutorDocument::factory()
        ->for($profile, 'tutorProfile')
        ->for(DocumentType::factory(), 'documentType')
        ->create(['disk_path' => 'tutor-documents/'.$profile->id.'/sample.pdf']);

    Storage::disk('local')->put($document->disk_path, 'fake pdf contents');

    return $document;
}

it('lets the owning tutor download via a fresh signed URL', function () {
    $document = createOwnedTutorDocument();
    $url = URL::temporarySignedRoute('tutor.documents.show', now()->addMinutes(15), ['document' => $document]);

    $response = $this->actingAs($document->tutorProfile->user)->get($url);

    $response->assertOk();
});

it('refuses a different tutor even with a valid signature', function () {
    $document = createOwnedTutorDocument();
    $url = URL::temporarySignedRoute('tutor.documents.show', now()->addMinutes(15), ['document' => $document]);

    $otherTutor = User::factory()->tutor()->create();

    $response = $this->actingAs($otherTutor)->get($url);

    $response->assertForbidden();
});

it('refuses an expired signed URL', function () {
    $document = createOwnedTutorDocument();
    $url = URL::temporarySignedRoute('tutor.documents.show', now()->addMinutes(15), ['document' => $document]);

    $this->travel(16)->minutes();

    $response = $this->actingAs($document->tutorProfile->user)->get($url);

    $response->assertForbidden();
});

it('refuses an unsigned request to the same path', function () {
    $document = createOwnedTutorDocument();

    $response = $this->actingAs($document->tutorProfile->user)
        ->get(route('tutor.documents.show', ['document' => $document]));

    $response->assertForbidden();
});

it('does not serve the file through the framework\'s own local-disk route', function () {
    $document = createOwnedTutorDocument();

    $response = $this->actingAs($document->tutorProfile->user)
        ->get('/storage/'.$document->disk_path);

    $response->assertNotFound();
});
