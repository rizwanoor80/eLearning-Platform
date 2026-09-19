<?php

use App\Actions\RecordAuditLog;
use App\Actions\Tutor\ReviewTutorDocument;
use App\Enums\TutorDocumentStatus;
use App\Enums\TutorProfileStatus;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\TutorDocument;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ---- (d) replaced document files ------------------------------------------------------

it('deletes the files of replaced documents once the replacement is accepted, keeping the rows (R36 d)', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $type = DocumentType::factory()->create();
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::ChangesRequested]);
    Storage::disk('local')->put('tutor-documents/old-1.pdf', 'first');
    Storage::disk('local')->put('tutor-documents/old-2.pdf', 'second');
    Storage::disk('local')->put('tutor-documents/new.pdf', 'third');
    // One current row per tutor and type (partial unique index): replace twice, like two re-uploads.
    $old1 = TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')->create(['disk_path' => 'tutor-documents/old-1.pdf']);
    $old1->delete();
    $old2 = TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')->create(['disk_path' => 'tutor-documents/old-2.pdf']);
    $old2->delete();
    $current = TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')->create(['disk_path' => 'tutor-documents/new.pdf']);

    // Not yet accepted: nothing is deleted, in case the replacement is rejected.
    Storage::disk('local')->assertExists(['tutor-documents/old-1.pdf', 'tutor-documents/old-2.pdf']);

    (new ReviewTutorDocument(app(RecordAuditLog::class)))($admin, $current, TutorDocumentStatus::Accepted);

    Storage::disk('local')->assertMissing(['tutor-documents/old-1.pdf', 'tutor-documents/old-2.pdf']);
    Storage::disk('local')->assertExists('tutor-documents/new.pdf');
    expect(TutorDocument::withTrashed()->whereKey([$old1->id, $old2->id])->count())->toBe(2);
});

it('keeps the older files while the replacement is only rejected, and ignores another tutor’s or type’s files (R36 d)', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $type = DocumentType::factory()->create();
    $otherType = DocumentType::factory()->create();
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::ChangesRequested]);
    $stranger = TutorProfile::factory()->create(['status' => TutorProfileStatus::ChangesRequested]);
    Storage::disk('local')->put('own-old.pdf', 'x');
    Storage::disk('local')->put('other-type-old.pdf', 'x');
    Storage::disk('local')->put('stranger-old.pdf', 'x');
    TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')->create(['disk_path' => 'own-old.pdf'])->delete();
    TutorDocument::factory()->for($profile, 'tutorProfile')->for($otherType, 'documentType')->create(['disk_path' => 'other-type-old.pdf'])->delete();
    TutorDocument::factory()->for($stranger, 'tutorProfile')->for($type, 'documentType')->create(['disk_path' => 'stranger-old.pdf'])->delete();
    $current = TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')->create(['disk_path' => 'new.pdf']);

    (new ReviewTutorDocument(app(RecordAuditLog::class)))($admin, $current, TutorDocumentStatus::Rejected);
    Storage::disk('local')->assertExists(['own-old.pdf', 'other-type-old.pdf', 'stranger-old.pdf']);

    (new ReviewTutorDocument(app(RecordAuditLog::class)))($admin, $current, TutorDocumentStatus::Accepted);
    Storage::disk('local')->assertMissing('own-old.pdf');
    Storage::disk('local')->assertExists(['other-type-old.pdf', 'stranger-old.pdf']);
});

it('does not fail when a replaced file is already gone from the disk (R36 d)', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $type = DocumentType::factory()->create();
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview]);
    TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')->create(['disk_path' => 'never-existed.pdf'])->delete();
    $current = TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')->create(['disk_path' => 'new.pdf']);

    (new ReviewTutorDocument(app(RecordAuditLog::class)))($admin, $current, TutorDocumentStatus::Accepted);

    expect($current->fresh()->status)->toBe(TutorDocumentStatus::Accepted);
});

it('removes an uploaded file that no row points to when saving the document row fails (R36 d)', function () {
    Storage::fake('local');
    DocumentType::query()->delete();
    DocumentType::factory()->create(['required' => true, 'active' => true]);
    $tutor = User::factory()->tutor()->create();
    $this->actingAs($tutor)->post(route('tutor.onboarding.permit'), [
        'permit_number' => 'PMT-1',
        'permit_expires_at' => now()->addYear()->toDateString(),
    ]);
    TutorDocument::creating(fn () => throw new RuntimeException('disk full'));

    $this->withoutExceptionHandling();
    expect(fn () => $this->post(route('tutor.onboarding.documents'), ['file' => UploadedFile::fake()->create('permit.pdf', 10, 'application/pdf')]))
        ->toThrow(RuntimeException::class, 'disk full');

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(TutorDocument::query()->count())->toBe(0);
});

// ---- (e) the permit job ---------------------------------------------------------------

it('schedules the permit job daily on one server without overlapping runs (R36 e)', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'tutors:check-permits'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 6 * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});

// ---- (g) a permit edit resets an accepted scan ----------------------------------------

function pmDraftWithAcceptedScan(): array
{
    DocumentType::query()->delete();
    $permitType = DocumentType::factory()->create(['code' => DocumentType::PERMIT_CODE, 'required' => true, 'active' => true]);
    $otherType = DocumentType::factory()->create(['required' => true, 'active' => true]);
    $tutor = User::factory()->tutor()->create();
    $profile = TutorProfile::factory()->create([
        'user_id' => $tutor->id,
        'status' => TutorProfileStatus::Draft,
        'permit_number' => 'PMT-1',
        'permit_expires_at' => '2030-01-15',
    ]);
    $reviewer = User::factory()->admin()->create();
    $scan = TutorDocument::factory()->for($profile, 'tutorProfile')->for($permitType, 'documentType')->accepted()->create(['reviewed_by' => $reviewer->id]);
    $other = TutorDocument::factory()->for($profile, 'tutorProfile')->for($otherType, 'documentType')->accepted()->create(['reviewed_by' => $reviewer->id]);

    return [$tutor, $profile, $scan, $other];
}

it('resets an accepted permit scan to pending when the permit date changes (R36 g)', function () {
    [$tutor, $profile, $scan, $other] = pmDraftWithAcceptedScan();

    $this->actingAs($tutor)->post(route('tutor.onboarding.permit'), ['permit_number' => 'PMT-1', 'permit_expires_at' => '2031-06-30'])
        ->assertSessionHasNoErrors();

    expect($scan->fresh())->status->toBe(TutorDocumentStatus::Pending)->reviewed_by->toBeNull()->reviewed_at->toBeNull()
        ->and($other->fresh()->status)->toBe(TutorDocumentStatus::Accepted)
        ->and($profile->fresh()->permit_expires_at->toDateString())->toBe('2031-06-30');
    $log = AuditLog::query()->where('action', 'tutor_document.reset_to_pending')->firstOrFail();
    expect($log->actor_user_id)->toBe($tutor->id)->and($log->subject_id)->toBe($scan->id);
});

it('resets the scan when only the permit number changes (R36 g)', function () {
    [$tutor, , $scan] = pmDraftWithAcceptedScan();

    $this->actingAs($tutor)->post(route('tutor.onboarding.permit'), ['permit_number' => 'PMT-2', 'permit_expires_at' => '2030-01-15']);

    expect($scan->fresh()->status)->toBe(TutorDocumentStatus::Pending);
});

it('leaves the scan accepted when the same permit details are saved again (R36 g)', function () {
    [$tutor, , $scan] = pmDraftWithAcceptedScan();

    $this->actingAs($tutor)->post(route('tutor.onboarding.permit'), ['permit_number' => 'PMT-1', 'permit_expires_at' => '2030-01-15']);

    expect($scan->fresh()->status)->toBe(TutorDocumentStatus::Accepted)
        ->and(AuditLog::query()->where('action', 'tutor_document.reset_to_pending')->exists())->toBeFalse();
});

it('leaves a rejected or pending permit scan as it is when the permit changes (R36 g)', function (TutorDocumentStatus $status) {
    [$tutor, , $scan] = pmDraftWithAcceptedScan();
    $scan->forceFill(['status' => $status])->save();

    $this->actingAs($tutor)->post(route('tutor.onboarding.permit'), ['permit_number' => 'PMT-9', 'permit_expires_at' => '2032-01-01']);

    expect($scan->fresh()->status)->toBe($status);
})->with([
    'rejected' => TutorDocumentStatus::Rejected,
    'pending' => TutorDocumentStatus::Pending,
]);

it('changes the permit cleanly when there is no permit document type or no scan at all (R36 g)', function () {
    DocumentType::query()->delete(); // agreementReadyTutor() does the same
    $tutor = User::factory()->tutor()->create();
    TutorProfile::factory()->create(['user_id' => $tutor->id, 'status' => TutorProfileStatus::Draft, 'permit_number' => 'PMT-1', 'permit_expires_at' => '2030-01-15']);

    $this->actingAs($tutor)->post(route('tutor.onboarding.permit'), ['permit_number' => 'PMT-2', 'permit_expires_at' => '2031-01-01'])
        ->assertSessionHasNoErrors();

    DocumentType::factory()->create(['code' => DocumentType::PERMIT_CODE, 'required' => true, 'active' => true]);
    $this->actingAs($tutor)->post(route('tutor.onboarding.permit'), ['permit_number' => 'PMT-3', 'permit_expires_at' => '2032-01-01'])
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('action', 'tutor_document.reset_to_pending')->exists())->toBeFalse();
});

it('reopens the approval gate when the permit scan is reset', function () {
    [$tutor, $profile] = pmDraftWithAcceptedScan();
    expect($profile->fresh()->hasAllRequiredDocumentsAccepted())->toBeTrue();

    $this->actingAs($tutor)->post(route('tutor.onboarding.permit'), ['permit_number' => 'PMT-1', 'permit_expires_at' => '2031-06-30']);

    expect($profile->fresh()->hasAllRequiredDocumentsAccepted())->toBeFalse();
});
