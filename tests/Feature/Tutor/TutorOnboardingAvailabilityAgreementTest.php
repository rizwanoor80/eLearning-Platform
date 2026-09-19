<?php

use App\Actions\RecordAuditLog;
use App\Actions\Tutor\ApproveTutor;
use App\Actions\Tutor\ReviewTutorDocument;
use App\Enums\CurriculumCode;
use App\Enums\LevelTier;
use App\Enums\TutorDocumentStatus;
use App\Enums\TutorProfileStatus;
use App\Exceptions\TutorApprovalBlockedException;
use App\Mail\Tutor\TutorSubmittedForReviewMail;
use App\Models\Curriculum;
use App\Models\DocumentType;
use App\Models\Page;
use App\Models\PriceBand;
use App\Models\Subject;
use App\Models\TutorDocument;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Page::factory()->create(['slug' => 'tutor_agreement', 'version' => 1]);
});

it('moves through profile and availability to the agreement step', function () {
    $tutor = agreementReadyTutor();

    $response = test()->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page->component('tutor/Onboarding')->where('step', 'agreement'));
});

it('rejects overlapping availability rules on the same weekday', function () {
    DocumentType::query()->delete();
    $tutor = User::factory()->tutor()->create();
    test()->actingAs($tutor)->post(route('tutor.onboarding.permit'), [
        'permit_number' => 'PMT-1',
        'permit_expires_at' => now()->addYear()->toDateString(),
    ]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.bank'), [
        'bank_name' => 'Emirates NBD', 'bank_account_name' => 'Test Tutor', 'bank_iban' => 'AE070331234567890123456',
    ]);
    $curriculum = Curriculum::factory()->create(['code' => CurriculumCode::Gcse]);
    $subject = Subject::factory()->create();
    PriceBand::factory()->create([
        'curriculum_id' => $curriculum->id, 'level_tier' => LevelTier::Exam1,
        'min_rate' => 10000, 'max_rate' => 20000, 'effective_from' => now()->subYear()->toDateString(),
    ]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), [
        'subjects' => [['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'level_min' => 'Y10', 'level_max' => 'Y11', 'level_tier' => 'exam_1']],
    ]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.rate'), ['hourly_rate' => '150.00']);
    test()->actingAs($tutor)->post(route('tutor.onboarding.profile'), [
        'headline' => 'Tutor', 'bio' => 'Bio text here.',
    ]);

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.availability'), [
        'rules' => [
            ['weekday' => 1, 'start_time' => '16:00', 'end_time' => '18:00'],
            ['weekday' => 1, 'start_time' => '17:00', 'end_time' => '19:00'],
        ],
    ]);

    $response->assertSessionHasErrors('rules');
});

it('records the current page version when the agreement is accepted', function () {
    $tutor = agreementReadyTutor();
    Page::query()->where('slug', 'tutor_agreement')->update(['version' => 2]);

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 2]);

    $response->assertRedirect(route('tutor.onboarding'));
    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    expect($profile->agreement_version)->toBe(2)
        ->and($profile->agreement_accepted_at)->not->toBeNull();
});

it('reaches the complete step once the agreement is accepted', function () {
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1]);

    $response = test()->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page->where('step', 'complete'));
});

it('sets the profile to pending_review on completion', function () {
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1]);

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));

    $response->assertRedirect(route('tutor.onboarding'));
    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    expect($profile->status)->toBe(TutorProfileStatus::PendingReview);
});

it('blocks completion without an accepted agreement', function () {
    $tutor = agreementReadyTutor();

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));

    $response->assertStatus(409);
    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    expect($profile->status)->toBe(TutorProfileStatus::Draft);
});

it('shows submitted once the profile has left draft status', function () {
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));

    $response = test()->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page->where('step', 'submitted'));
});

it('sends the submitted-for-review email on completion', function () {
    Mail::fake();
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1]);

    test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));

    Mail::assertQueued(TutorSubmittedForReviewMail::class, fn ($mail) => $mail->hasTo($tutor->email));
});

it('re-enters at complete and shows the admin review note when changes are requested', function () {
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));

    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    $profile->forceFill([
        'status' => TutorProfileStatus::ChangesRequested,
        'review_note' => 'Please upload a clearer permit scan.',
    ])->save();

    $response = test()->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page
        ->where('step', 'complete')
        ->where('reviewNote', 'Please upload a clearer permit scan.'));
});

it('lets a changes_requested tutor edit an earlier step again', function () {
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));

    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    $profile->forceFill([
        'status' => TutorProfileStatus::ChangesRequested,
        'review_note' => 'Update your bio.',
    ])->save();

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.profile'), [
        'headline' => 'Updated headline',
        'bio' => 'Updated bio text here.',
    ]);

    $response->assertRedirect(route('tutor.onboarding'));
    expect($profile->fresh()->headline)->toBe('Updated headline');
});

it('resubmits a changes_requested profile for review on completion', function () {
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));

    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    $profile->forceFill(['status' => TutorProfileStatus::ChangesRequested, 'review_note' => 'Fix your rate.'])->save();

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));

    $response->assertRedirect(route('tutor.onboarding'));
    expect($profile->fresh()->status)->toBe(TutorProfileStatus::PendingReview);
});

it('refuses completion when an admin narrows the price band after the rate step but before completion', function () {
    // R27 defence in depth: nothing in the normal flow re-touches the
    // subjects between the rate and agreement steps here, so this
    // exercises complete()'s own re-check directly rather than the
    // subjects-change path already covered in the rate test file.
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1]);

    $curriculum = Curriculum::query()->where('code', CurriculumCode::Gcse)->firstOrFail();
    PriceBand::factory()->create([
        'curriculum_id' => $curriculum->id,
        'level_tier' => LevelTier::Exam1,
        'min_rate' => 16000,
        'max_rate' => 20000,
        'effective_from' => now()->toDateString(),
    ]);

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));

    $response->assertStatus(409);
    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    expect($profile->status)->toBe(TutorProfileStatus::Draft);
});

it('refuses every step handler once the profile has been submitted for review', function () {
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));

    test()->actingAs($tutor)->post(route('tutor.onboarding.rate'), ['hourly_rate' => '150.00'])
        ->assertStatus(409);
    test()->actingAs($tutor)->post(route('tutor.onboarding.bank'), [
        'bank_name' => 'Other Bank', 'bank_account_name' => 'Someone Else', 'bank_iban' => 'AE999999999999999999999',
    ])->assertStatus(409);
});

/**
 * R28: a rejected document does not count as uploaded, so a changes_requested
 * tutor returns to that type's step and can replace it.
 */
function changesRequestedTutorWithDocument(TutorDocumentStatus $documentStatus): array
{
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));

    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    $type = DocumentType::factory()->create(['required' => true, 'active' => true, 'sort' => 0]);
    $document = TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')
        ->create(['status' => $documentStatus]);
    $profile->forceFill(['status' => TutorProfileStatus::ChangesRequested, 'review_note' => 'Re-upload it.'])->save();

    return [$tutor, $profile, $type, $document];
}

it('returns a changes_requested tutor to the document step when their document was rejected', function () {
    [$tutor, , $type] = changesRequestedTutorWithDocument(TutorDocumentStatus::Rejected);

    test()->actingAs($tutor)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('step', 'document')->where('currentDocumentType.id', $type->id));
});

it('still counts a pending or accepted document as uploaded', function (TutorDocumentStatus $status) {
    [$tutor] = changesRequestedTutorWithDocument($status);

    test()->actingAs($tutor)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('step', 'complete'));
})->with([TutorDocumentStatus::Pending, TutorDocumentStatus::Accepted]);

it('replaces a rejected document, then resubmits and is approved only once the replacement is accepted', function () {
    Event::fake();
    Storage::fake('local');
    [$tutor, $profile, $type, $rejected] = changesRequestedTutorWithDocument(TutorDocumentStatus::Rejected);
    $admin = User::factory()->admin()->create();

    test()->actingAs($tutor)->post(route('tutor.onboarding.documents'), [
        'file' => UploadedFile::fake()->create('permit-clear.pdf', 100, 'application/pdf'),
    ])->assertRedirect(route('tutor.onboarding'));

    // Old row soft-deleted, a fresh pending one created (1a's partial unique index allows it).
    expect(TutorDocument::withTrashed()->find($rejected->id)->trashed())->toBeTrue();
    $replacement = TutorDocument::query()->where('tutor_profile_id', $profile->id)->where('document_type_id', $type->id)->firstOrFail();
    expect($replacement->status)->toBe(TutorDocumentStatus::Pending);

    test()->actingAs($tutor)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('step', 'complete'));
    test()->actingAs($tutor)->post(route('tutor.onboarding.complete'))->assertRedirect(route('tutor.onboarding'));
    expect($profile->fresh()->status)->toBe(TutorProfileStatus::PendingReview);

    // Approval stays blocked until the replacement is accepted, then goes through.
    $approve = new ApproveTutor(app(RecordAuditLog::class));
    expect(fn () => $approve($admin, $profile->fresh()))->toThrow(TutorApprovalBlockedException::class);
    (new ReviewTutorDocument(app(RecordAuditLog::class)))($admin, $replacement, TutorDocumentStatus::Accepted);
    $approve($admin, $profile->fresh());
    expect($profile->fresh()->status)->toBe(TutorProfileStatus::Approved);
});

it('keeps completion blocked until a rejected document is replaced', function () {
    [$tutor] = changesRequestedTutorWithDocument(TutorDocumentStatus::Rejected);

    test()->actingAs($tutor)->post(route('tutor.onboarding.complete'))->assertStatus(409);
});

it('passes the status and, for changes_requested and suspended only, the admin note to the page (R31)', function (TutorProfileStatus $status, bool $noteShown) {
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));
    TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail()
        ->forceFill(['status' => $status, 'review_note' => 'Because reasons.'])->save();

    test()->actingAs($tutor)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page
            ->where('status', $status->value)
            ->where('reviewNote', $noteShown ? 'Because reasons.' : null));
})->with([
    'changes requested' => [TutorProfileStatus::ChangesRequested, true],
    'suspended' => [TutorProfileStatus::Suspended, true],
    'approved' => [TutorProfileStatus::Approved, false],
    'rejected' => [TutorProfileStatus::Rejected, false],
    'pending review' => [TutorProfileStatus::PendingReview, false],
]);

it('lets a changes_requested tutor edit an earlier step and lands back on complete (R31)', function () {
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));
    TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail()
        ->forceFill(['status' => TutorProfileStatus::ChangesRequested, 'review_note' => 'Tidy your bio.'])->save();

    test()->actingAs($tutor)->post(route('tutor.onboarding.profile'), ['headline' => 'Better headline', 'bio' => 'A clearer bio.'])
        ->assertRedirect(route('tutor.onboarding'));

    test()->actingAs($tutor)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('step', 'complete')->where('profile.headline', 'Better headline'));
});
