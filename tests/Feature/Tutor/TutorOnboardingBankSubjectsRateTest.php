<?php

use App\Enums\CurriculumCode;
use App\Enums\LevelTier;
use App\Models\Curriculum;
use App\Models\DocumentType;
use App\Models\PriceBand;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\User;

function bankReadyTutor(): User
{
    DocumentType::query()->delete();

    $tutor = User::factory()->tutor()->create();
    test()->actingAs($tutor)->post(route('tutor.onboarding.permit'), [
        'permit_number' => 'PMT-1',
        'permit_expires_at' => now()->addYear()->toDateString(),
    ]);

    return $tutor;
}

function subjectsReadyTutor(): array
{
    $tutor = bankReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.bank'), [
        'bank_name' => 'Emirates NBD',
        'bank_account_name' => 'Test Tutor',
        'bank_iban' => 'AE070331234567890123456',
        'bank_swift' => 'EBILAEAD',
    ]);

    $curriculum = Curriculum::factory()->create(['code' => CurriculumCode::Gcse]);
    $subject = Subject::factory()->create();

    return [$tutor, $curriculum, $subject];
}

it('saves the bank step with an encrypted IBAN', function () {
    $tutor = bankReadyTutor();

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.bank'), [
        'bank_name' => 'Emirates NBD',
        'bank_account_name' => 'Test Tutor',
        'bank_iban' => 'AE070331234567890123456',
        'bank_swift' => 'EBILAEAD',
    ]);

    $response->assertRedirect(route('tutor.onboarding'));
    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    expect($profile->bank_iban)->toBe('AE070331234567890123456');
});

it('moves to the subjects step once bank details are saved', function () {
    $tutor = bankReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.bank'), [
        'bank_name' => 'Emirates NBD',
        'bank_account_name' => 'Test Tutor',
        'bank_iban' => 'AE070331234567890123456',
    ]);

    $response = test()->actingAs($tutor)->get(route('tutor.onboarding'));

    $response->assertInertia(fn ($page) => $page->where('step', 'subjects'));
});

it('saves a subjects row and derives no tier mismatch when the tier is valid for the curriculum', function () {
    [$tutor, $curriculum, $subject] = subjectsReadyTutor();

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), [
        'subjects' => [[
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'level_min' => 'Year 10',
            'level_max' => 'Year 11',
            'level_tier' => 'exam_1',
        ]],
    ]);

    $response->assertRedirect(route('tutor.onboarding'));
    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    expect($profile->tutorSubjects()->count())->toBe(1)
        ->and($profile->tutorSubjects()->first()->level_tier)->toBe(LevelTier::Exam1);
});

it('rejects a level tier that does not exist for the chosen curriculum', function () {
    [$tutor, $curriculum, $subject] = subjectsReadyTutor();

    // GCSE only has lower_secondary and exam_1 (App\Enums\CurriculumCode::tiers()) — exam_2 is A-Level/IB DP only.
    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), [
        'subjects' => [[
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'level_min' => 'Year 12',
            'level_max' => 'Year 13',
            'level_tier' => 'exam_2',
        ]],
    ]);

    $response->assertSessionHasErrors('subjects');
    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    expect($profile->tutorSubjects()->count())->toBe(0);
});

it('rejects a duplicate subject listed twice for the same curriculum', function () {
    [$tutor, $curriculum, $subject] = subjectsReadyTutor();

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), [
        'subjects' => [
            ['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'level_min' => 'Y7', 'level_max' => 'Y9', 'level_tier' => 'lower_secondary'],
            ['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'level_min' => 'Y10', 'level_max' => 'Y11', 'level_tier' => 'exam_1'],
        ],
    ]);

    $response->assertSessionHasErrors('subjects');
});

it('rejects an hourly rate outside the price band for the highest tier taught, showing the band', function () {
    [$tutor, $curriculum, $subject] = subjectsReadyTutor();
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

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.rate'), [
        'hourly_rate' => '999.00',
    ]);

    $response->assertSessionHasErrors('hourly_rate');
    expect(session('errors')->first('hourly_rate'))
        ->toContain('100.00')
        ->toContain('200.00');
});

it('accepts an hourly rate within the price band', function () {
    [$tutor, $curriculum, $subject] = subjectsReadyTutor();
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

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.rate'), [
        'hourly_rate' => '150.00',
    ]);

    $response->assertRedirect(route('tutor.onboarding'));
    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    expect($profile->hourly_rate->toFils())->toBe(15000);
});

it('refuses the rate step before any subject has been added', function () {
    $tutor = bankReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.bank'), [
        'bank_name' => 'Emirates NBD',
        'bank_account_name' => 'Test Tutor',
        'bank_iban' => 'AE070331234567890123456',
    ]);

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.rate'), [
        'hourly_rate' => '150.00',
    ]);

    $response->assertStatus(409);
});
