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

/**
 * R26: the rate step validates the intersection of every curriculum's band
 * at the tutor's highest tier taught, never the union.
 */
function twoCurriculumSubjectsTutor(int $bandAMin, int $bandAMax, int $bandBMin, int $bandBMax): array
{
    $tutor = bankReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.bank'), [
        'bank_name' => 'Emirates NBD',
        'bank_account_name' => 'Test Tutor',
        'bank_iban' => 'AE070331234567890123456',
    ]);

    $curriculumA = Curriculum::factory()->create(['code' => CurriculumCode::Gcse]);
    $curriculumB = Curriculum::factory()->create(['code' => CurriculumCode::IbMyp]);
    $subjectA = Subject::factory()->create();
    $subjectB = Subject::factory()->create();

    PriceBand::factory()->create([
        'curriculum_id' => $curriculumA->id, 'level_tier' => LevelTier::Exam1,
        'min_rate' => $bandAMin, 'max_rate' => $bandAMax, 'effective_from' => now()->subYear()->toDateString(),
    ]);
    PriceBand::factory()->create([
        'curriculum_id' => $curriculumB->id, 'level_tier' => LevelTier::Exam1,
        'min_rate' => $bandBMin, 'max_rate' => $bandBMax, 'effective_from' => now()->subYear()->toDateString(),
    ]);

    test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), [
        'subjects' => [
            ['curriculum_id' => $curriculumA->id, 'subject_id' => $subjectA->id, 'level_min' => 'Y10', 'level_max' => 'Y11', 'level_tier' => 'exam_1'],
            ['curriculum_id' => $curriculumB->id, 'subject_id' => $subjectB->id, 'level_min' => 'MYP4', 'level_max' => 'MYP5', 'level_tier' => 'exam_1'],
        ],
    ]);

    return [$tutor, $curriculumA, $curriculumB];
}

it('accepts an hourly rate when two curricula share an identical band, exactly as with one curriculum', function () {
    [$tutor] = twoCurriculumSubjectsTutor(10000, 20000, 10000, 20000);

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.rate'), ['hourly_rate' => '150.00']);

    $response->assertRedirect(route('tutor.onboarding'));
    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    expect($profile->hourly_rate->toFils())->toBe(15000);
});

it('validates the intersection of two overlapping-but-different bands, not the union', function () {
    // Curriculum A: 100–200 AED/hr. Curriculum B: 150–300 AED/hr. Intersection: 150–200.
    [$tutor, $curriculumA] = twoCurriculumSubjectsTutor(10000, 20000, 15000, 30000);

    $insideBoth = test()->actingAs($tutor)->post(route('tutor.onboarding.rate'), ['hourly_rate' => '175.00']);
    $insideBoth->assertRedirect(route('tutor.onboarding'));
    expect(TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail()->hourly_rate->toFils())->toBe(17500);

    // 250 is inside B's band [150,300] but outside A's [100,200] — a union
    // would wrongly accept it; the intersection [150,200] must reject it.
    $tutor->fresh();
    $onlyInB = test()->actingAs($tutor)->post(route('tutor.onboarding.rate'), ['hourly_rate' => '250.00']);
    $onlyInB->assertSessionHasErrors('hourly_rate');
    expect(session('errors')->first('hourly_rate'))
        ->toContain('150.00')
        ->toContain('200.00');
});

it('rejects every rate when two curricula have non-overlapping bands, naming both', function () {
    // Curriculum A: 100–200 AED/hr. Curriculum B: 250–300 AED/hr. No overlap.
    [$tutor, $curriculumA, $curriculumB] = twoCurriculumSubjectsTutor(10000, 20000, 25000, 30000);

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.rate'), ['hourly_rate' => '150.00']);

    $response->assertSessionHasErrors('hourly_rate');
    expect(session('errors')->first('hourly_rate'))
        ->toContain($curriculumA->name)
        ->toContain($curriculumB->name);
    expect(TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail()->hourly_rate)->toBeNull();
});

/**
 * R27: hourly_rate must never survive a subjects change that moves it
 * out of band — invalidateRateIfOutOfBand() clears it so the wizard sends
 * the tutor back through the rate step instead of letting a stale value
 * reach completion.
 */
it('clears a stale rate when a later subjects change no longer fits its band', function () {
    [$tutor, $curriculum, $subject] = subjectsReadyTutor();
    PriceBand::factory()->create([
        'curriculum_id' => $curriculum->id, 'level_tier' => LevelTier::Exam1,
        'min_rate' => 10000, 'max_rate' => 100000, 'effective_from' => now()->subYear()->toDateString(),
    ]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), [
        'subjects' => [[
            'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'level_min' => 'Year 10', 'level_max' => 'Year 11', 'level_tier' => 'exam_1',
        ]],
    ]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.rate'), ['hourly_rate' => '900.00']);
    expect(TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail()->hourly_rate->toFils())->toBe(90000);

    $narrowCurriculum = Curriculum::factory()->create(['code' => CurriculumCode::IbMyp]);
    $narrowSubject = Subject::factory()->create();
    PriceBand::factory()->create([
        'curriculum_id' => $narrowCurriculum->id, 'level_tier' => LevelTier::Exam1,
        'min_rate' => 10000, 'max_rate' => 20000, 'effective_from' => now()->subYear()->toDateString(),
    ]);

    test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), [
        'subjects' => [[
            'curriculum_id' => $narrowCurriculum->id, 'subject_id' => $narrowSubject->id,
            'level_min' => 'MYP4', 'level_max' => 'MYP5', 'level_tier' => 'exam_1',
        ]],
    ]);

    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    expect($profile->hourly_rate)->toBeNull();

    $response = test()->actingAs($tutor)->get(route('tutor.onboarding'));
    $response->assertInertia(fn ($page) => $page->where('step', 'rate'));
});

it('keeps the rate when a later subjects change still fits its band', function () {
    [$tutor, $curriculum, $subject] = subjectsReadyTutor();
    PriceBand::factory()->create([
        'curriculum_id' => $curriculum->id, 'level_tier' => LevelTier::Exam1,
        'min_rate' => 10000, 'max_rate' => 20000, 'effective_from' => now()->subYear()->toDateString(),
    ]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), [
        'subjects' => [[
            'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'level_min' => 'Year 10', 'level_max' => 'Year 11', 'level_tier' => 'exam_1',
        ]],
    ]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.rate'), ['hourly_rate' => '150.00']);

    $secondSubject = Subject::factory()->create();
    test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), [
        'subjects' => [
            ['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'level_min' => 'Y10', 'level_max' => 'Y11', 'level_tier' => 'exam_1'],
            ['curriculum_id' => $curriculum->id, 'subject_id' => $secondSubject->id, 'level_min' => 'Y10', 'level_max' => 'Y11', 'level_tier' => 'exam_1'],
        ],
    ]);

    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();
    expect($profile->hourly_rate->toFils())->toBe(15000);
});

it('names a curriculum with no current price band as a conflict, rather than silently dropping it', function () {
    [$tutor, $curriculum, $subject] = subjectsReadyTutor();
    // Deliberately no PriceBand row for this curriculum/tier at all.
    test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), [
        'subjects' => [[
            'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'level_min' => 'Year 10', 'level_max' => 'Year 11', 'level_tier' => 'exam_1',
        ]],
    ]);

    $response = test()->actingAs($tutor)->post(route('tutor.onboarding.rate'), ['hourly_rate' => '150.00']);

    $response->assertSessionHasErrors('hourly_rate');
    expect(session('errors')->first('hourly_rate'))->toContain($curriculum->name);
    expect(TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail()->hourly_rate)->toBeNull();
});
