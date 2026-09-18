<?php

use App\Enums\CurriculumCode;
use App\Enums\LevelTier;
use App\Enums\TutorProfileStatus;
use App\Mail\Tutor\TutorSubmittedForReviewMail;
use App\Models\Curriculum;
use App\Models\DocumentType;
use App\Models\Page;
use App\Models\PriceBand;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

function agreementReadyTutor(): User
{
    DocumentType::query()->delete();

    $tutor = User::factory()->tutor()->create();
    test()->actingAs($tutor)->post(route('tutor.onboarding.permit'), [
        'permit_number' => 'PMT-1',
        'permit_expires_at' => now()->addYear()->toDateString(),
    ]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.bank'), [
        'bank_name' => 'Emirates NBD',
        'bank_account_name' => 'Test Tutor',
        'bank_iban' => 'AE070331234567890123456',
    ]);

    $curriculum = Curriculum::factory()->create(['code' => CurriculumCode::Gcse]);
    $subject = Subject::factory()->create();
    PriceBand::factory()->create([
        'curriculum_id' => $curriculum->id,
        'level_tier' => LevelTier::Exam1,
        'min_rate' => 10000,
        'max_rate' => 20000,
        'effective_from' => now()->subYear()->toDateString(),
    ]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), [
        'subjects' => [[
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'level_min' => 'Year 10',
            'level_max' => 'Year 11',
            'level_tier' => 'exam_1',
        ]],
    ]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.rate'), ['hourly_rate' => '150.00']);
    test()->actingAs($tutor)->post(route('tutor.onboarding.profile'), [
        'headline' => 'Experienced GCSE Maths tutor',
        'bio' => 'I have taught GCSE maths for ten years.',
    ]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.availability'), [
        'rules' => [['weekday' => 1, 'start_time' => '16:00', 'end_time' => '18:00']],
    ]);

    return $tutor;
}

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

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true]);

    $response->assertRedirect(route('tutor.onboarding'));
    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    expect($profile->agreement_version)->toBe(2)
        ->and($profile->agreement_accepted_at)->not->toBeNull();
});

it('reaches the complete step once the agreement is accepted', function () {
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true]);

    $response = test()->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page->where('step', 'complete'));
});

it('sets the profile to pending_review on completion', function () {
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true]);

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
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));

    $response = test()->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page->where('step', 'submitted'));
});

it('sends the submitted-for-review email on completion', function () {
    Mail::fake();
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true]);

    test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));

    Mail::assertQueued(TutorSubmittedForReviewMail::class, fn ($mail) => $mail->hasTo($tutor->email));
});

it('re-enters at complete and shows the admin review note when changes are requested', function () {
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true]);
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
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true]);
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
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true]);
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
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true]);

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
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.complete'));

    test()->actingAs($tutor)->post(route('tutor.onboarding.rate'), ['hourly_rate' => '150.00'])
        ->assertStatus(409);
    test()->actingAs($tutor)->post(route('tutor.onboarding.bank'), [
        'bank_name' => 'Other Bank', 'bank_account_name' => 'Someone Else', 'bank_iban' => 'AE999999999999999999999',
    ])->assertStatus(409);
});
