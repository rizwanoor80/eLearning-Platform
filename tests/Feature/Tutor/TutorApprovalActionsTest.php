<?php

use App\Actions\RecordAuditLog;
use App\Actions\Tutor\ApproveTutor;
use App\Actions\Tutor\RejectTutor;
use App\Actions\Tutor\RequestTutorChanges;
use App\Actions\Tutor\ReviewTutorDocument;
use App\Actions\Tutor\SuspendTutor;
use App\Enums\TutorDocumentStatus;
use App\Enums\TutorProfileStatus;
use App\Events\Tutor\TutorApproved;
use App\Events\Tutor\TutorChangesRequested;
use App\Events\Tutor\TutorRejected;
use App\Exceptions\TutorApprovalBlockedException;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\TutorDocument;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\Event;

it('approves a tutor when every required document is accepted, writing an audit row', function () {
    Event::fake();
    $admin = User::factory()->admin()->create();
    $requiredType = DocumentType::factory()->create(['required' => true, 'active' => true]);
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);
    TutorDocument::factory()->for($profile, 'tutorProfile')->for($requiredType, 'documentType')
        ->accepted()->create();

    (new ApproveTutor(app(RecordAuditLog::class)))($admin, $profile);

    expect($profile->fresh())
        ->status->toBe(TutorProfileStatus::Approved)
        ->approved_by->toBe($admin->id)
        ->approved_at->not->toBeNull();

    $log = AuditLog::query()->where('subject_type', TutorProfile::class)->where('subject_id', $profile->id)->firstOrFail();
    expect($log->action)->toBe('tutor.approved')
        ->and($log->actor_user_id)->toBe($admin->id);

    Event::assertDispatched(TutorApproved::class);
});

it('blocks approval while a required document type lacks an accepted document', function () {
    $admin = User::factory()->admin()->create();
    DocumentType::factory()->create(['required' => true, 'active' => true]);
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);

    expect(fn () => (new ApproveTutor(app(RecordAuditLog::class)))($admin, $profile))
        ->toThrow(TutorApprovalBlockedException::class);

    expect($profile->fresh()->status)->toBe(TutorProfileStatus::PendingReview)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('allows approval once the required document is accepted, ignoring an inactive required type', function () {
    Event::fake();
    $admin = User::factory()->admin()->create();
    DocumentType::factory()->create(['required' => true, 'active' => false]);
    $activeRequired = DocumentType::factory()->create(['required' => true, 'active' => true]);
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);
    TutorDocument::factory()->for($profile, 'tutorProfile')->for($activeRequired, 'documentType')->accepted()->create();

    (new ApproveTutor(app(RecordAuditLog::class)))($admin, $profile);

    expect($profile->fresh()->status)->toBe(TutorProfileStatus::Approved);
});

it('rejects a tutor with a note and writes an audit row', function () {
    Event::fake();
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);

    (new RejectTutor(app(RecordAuditLog::class)))($admin, $profile, 'Documents do not meet our requirements.');

    expect($profile->fresh())
        ->status->toBe(TutorProfileStatus::Rejected)
        ->review_note->toBe('Documents do not meet our requirements.');
    Event::assertDispatched(TutorRejected::class);
});

it('requests changes with a note and writes an audit row', function () {
    Event::fake();
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);

    (new RequestTutorChanges(app(RecordAuditLog::class)))($admin, $profile, 'Please re-upload your permit scan.');

    expect($profile->fresh())
        ->status->toBe(TutorProfileStatus::ChangesRequested)
        ->review_note->toBe('Please re-upload your permit scan.');
    Event::assertDispatched(TutorChangesRequested::class);
});

it('suspends an approved tutor with a note and writes an audit row', function () {
    $admin = User::factory()->admin()->create();
    $profile = TutorProfile::factory()->approved()->create();

    (new SuspendTutor(app(RecordAuditLog::class)))($admin, $profile, 'Repeated late cancellations.');

    expect($profile->fresh())
        ->status->toBe(TutorProfileStatus::Suspended)
        ->review_note->toBe('Repeated late cancellations.');
});

it('accepts a tutor document, recording the reviewer and writing an audit row', function () {
    $admin = User::factory()->admin()->create();
    $document = TutorDocument::factory()->create();

    (new ReviewTutorDocument(app(RecordAuditLog::class)))($admin, $document, TutorDocumentStatus::Accepted);

    expect($document->fresh())
        ->status->toBe(TutorDocumentStatus::Accepted)
        ->reviewed_by->toBe($admin->id)
        ->reviewed_at->not->toBeNull();
    expect(AuditLog::query()->where('action', 'tutor_document.accepted')->exists())->toBeTrue();
});

it('rejects a tutor document', function () {
    $admin = User::factory()->admin()->create();
    $document = TutorDocument::factory()->create();

    (new ReviewTutorDocument(app(RecordAuditLog::class)))($admin, $document, TutorDocumentStatus::Rejected);

    expect($document->fresh()->status)->toBe(TutorDocumentStatus::Rejected);
});
