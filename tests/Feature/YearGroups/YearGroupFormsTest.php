<?php

use App\Enums\CurriculumCode;
use App\Enums\LevelTier;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\MatchRequest;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Models\YearGroup;
use App\Services\Search\TutorPresenter;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\YearGroupSeeder;

// R33: forms use the controlled list (R30 #1, #5, #7, #12, #13, #11).
beforeEach(fn () => test()->seed([CurriculumSeeder::class, YearGroupSeeder::class]));

function ygCurriculum(CurriculumCode $code): Curriculum
{
    return Curriculum::query()->firstOrCreate(['code' => $code], ['name' => $code->value, 'sort' => 0]);
}

function ygId(Curriculum $curriculum, string $code): int
{
    return YearGroup::query()->where('curriculum_id', $curriculum->id)->where('code', $code)->firstOrFail()->id;
}

// ---- learners -------------------------------------------------------------------------------

it('refuses a year group of another curriculum, and any year group without a curriculum (R30 #1)', function () {
    $parent = User::factory()->create();
    $gcse = ygCurriculum(CurriculumCode::Gcse);
    $myp = ygCurriculum(CurriculumCode::IbMyp);

    test()->actingAs($parent)->post(route('learners.store'), ['display_name' => 'Amina', 'curriculum_id' => $gcse->id, 'year_group_id' => ygId($myp, 'myp4')])
        ->assertSessionHasErrors('year_group_id');
    test()->actingAs($parent)->post(route('learners.store'), ['display_name' => 'Amina', 'year_group_id' => ygId($gcse, 'y8')])
        ->assertSessionHasErrors(['curriculum_id', 'year_group_id']);
    expect(Learner::query()->count())->toBe(0);

    test()->actingAs($parent)->post(route('learners.store'), ['display_name' => 'Amina', 'curriculum_id' => $gcse->id, 'year_group_id' => ygId($gcse, 'y8')])
        ->assertSessionHasNoErrors();
    expect(Learner::query()->sole()->yearGroup->label)->toBe('Year 8');
});

it('makes a changed curriculum come with a year group of the new one (R30 #1)', function () {
    $parent = User::factory()->create();
    $gcse = ygCurriculum(CurriculumCode::Gcse);
    $myp = ygCurriculum(CurriculumCode::IbMyp);
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => $gcse->id, 'year_group_id' => ygId($gcse, 'y8')]);

    // The old (GCSE) year group no longer fits once the curriculum is MYP.
    test()->actingAs($parent)->put(route('learners.update', $learner), ['display_name' => 'Amina', 'curriculum_id' => $myp->id, 'year_group_id' => ygId($gcse, 'y8')])
        ->assertSessionHasErrors('year_group_id');
    expect($learner->fresh()->curriculum_id)->toBe($gcse->id);

    test()->actingAs($parent)->put(route('learners.update', $learner), ['display_name' => 'Amina', 'curriculum_id' => $myp->id, 'year_group_id' => ygId($myp, 'myp2')])
        ->assertSessionHasNoErrors();
    expect($learner->fresh()->year_group_id)->toBe(ygId($myp, 'myp2'));
});

it('lets an adult student save a curriculum without a year group yet', function () {
    $user = User::factory()->create();
    $self = Learner::factory()->selfLearner()->create(['account_user_id' => $user->id]);
    $gcse = ygCurriculum(CurriculumCode::Gcse);

    test()->actingAs($user)->put(route('learners.update', $self), ['curriculum_id' => $gcse->id])->assertSessionHasNoErrors();

    expect($self->fresh()->curriculum_id)->toBe($gcse->id)->and($self->fresh()->year_group_id)->toBeNull();
});

it('shows a legacy learner’s original text and asks for a year group on the next edit (R30 #13)', function () {
    $parent = User::factory()->create();
    $gcse = ygCurriculum(CurriculumCode::Gcse);
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => $gcse->id, 'year_group_id' => null]);
    $learner->forceFill(['year_group_legacy' => 'Reception'])->save();

    test()->actingAs($parent)->get(route('learners.edit', $learner))->assertInertia(fn ($page) => $page
        ->where('learner.year_group', 'Reception')->where('learner.year_group_is_legacy', true)->where('learner.year_group_id', null)->has('yearGroups', 21));

    test()->actingAs($parent)->get(route('learners.index'))->assertInertia(fn ($page) => $page->where('learners.0.year_group', 'Reception'));

    test()->actingAs($parent)->put(route('learners.update', $learner), ['display_name' => 'Amina', 'curriculum_id' => $gcse->id])
        ->assertSessionHasErrors('year_group_id');

    test()->actingAs($parent)->put(route('learners.update', $learner), ['display_name' => 'Amina', 'curriculum_id' => $gcse->id, 'year_group_id' => ygId($gcse, 'y7')])
        ->assertSessionHasNoErrors();
    expect($learner->fresh()->year_group_id)->toBe(ygId($gcse, 'y7'))->and($learner->fresh()->year_group_legacy)->toBeNull();
});

// ---- match requests -------------------------------------------------------------------------

it('refuses a match-request year group of another curriculum and stores the chosen label (R30 #7)', function () {
    $parent = User::factory()->create();
    $gcse = ygCurriculum(CurriculumCode::Gcse);
    $myp = ygCurriculum(CurriculumCode::IbMyp);
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => $gcse->id]);
    $base = ['learner_id' => $learner->id, 'curriculum_id' => $gcse->id, 'subject_id' => Subject::factory()->create()->id, 'goals' => 'Help', 'budget_tier' => 'mid'];

    test()->actingAs($parent)->post(route('match-requests.store'), [...$base, 'year_group_id' => ygId($myp, 'myp4')])->assertSessionHasErrors('year_group_id');

    test()->actingAs($parent)->post(route('match-requests.store'), [...$base, 'year_group_id' => ygId($gcse, 'y10')])->assertSessionHasNoErrors();
    expect(MatchRequest::query()->sole()->year_group)->toBe('Year 10');
});

// ---- onboarding -----------------------------------------------------------------------------

/**
 * @return array{0: User, 1: Curriculum, 2: Subject}
 */
function ygTutorAtAgreement(): array
{
    $tutor = agreementReadyTutor();
    $curriculum = Curriculum::query()->where('code', CurriculumCode::Gcse)->firstOrFail();

    return [$tutor, $curriculum, Subject::query()->firstOrFail()];
}

it('rejects a range that runs high to low, or that mixes curricula (R30 #5)', function () {
    [$tutor, $gcse, $subject] = ygTutorAtAgreement();
    $myp = ygCurriculum(CurriculumCode::IbMyp);

    test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), ['subjects' => [ygRow($gcse, $subject, 'y11', 'y10')]])->assertSessionHasErrors('subjects');
    test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), ['subjects' => [[...ygRow($gcse, $subject, 'y7', 'y9'), 'level_max_id' => ygId($myp, 'myp3')]]])->assertSessionHasErrors('subjects');

    // Nothing was replaced: the row from the tutor's earlier valid save is still there.
    expect(TutorSubject::query()->count())->toBe(1)->and(TutorSubject::query()->first()->levelMin->code)->toBe('y10');
});

it('derives the tier from the range, ignoring a tier sent by the client (R30 #5)', function () {
    [$tutor, $gcse, $subject] = ygTutorAtAgreement();

    // Year 7–9 is lower secondary, whatever the client claims.
    test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), ['subjects' => [[...ygRow($gcse, $subject, 'y7', 'y9'), 'level_tier' => 'exam_2']]])->assertSessionHasNoErrors();
    expect(TutorSubject::query()->sole()->level_tier)->toBe(LevelTier::LowerSecondary);

    // A range that crosses a tier boundary takes the highest tier in it.
    test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), ['subjects' => [ygRow($gcse, $subject, 'y9', 'y10')]])->assertSessionHasNoErrors();
    expect(TutorSubject::query()->sole()->level_tier)->toBe(LevelTier::Exam1);
});

it('sends a draft tutor with an unmapped row back to the subjects step, showing the original text (R30 #12)', function () {
    [$tutor] = ygTutorAtAgreement();

    test()->actingAs($tutor)->get(route('tutor.onboarding'))->assertInertia(fn ($page) => $page->where('step', 'agreement'));

    TutorSubject::query()->update(['level_min_id' => null, 'level_min_legacy' => 'Reception']);

    test()->actingAs($tutor)->get(route('tutor.onboarding'))->assertInertia(fn ($page) => $page
        ->where('step', 'subjects')
        ->where('tutorSubjects.0.level_min_id', null)
        ->where('tutorSubjects.0.level_min_legacy', 'Reception')
        ->has('yearGroups', 21));
});

// ---- the public face ------------------------------------------------------------------------

it('shows year-group labels on the public card, with the original text for an unmapped row (R30 #11)', function () {
    $gcse = ygCurriculum(CurriculumCode::Gcse);
    $tutor = TutorProfile::factory()->approved()->create();
    TutorSubject::factory()->create(['tutor_profile_id' => $tutor->id, 'curriculum_id' => $gcse->id, 'level_min_id' => ygId($gcse, 'y7'), 'level_max_id' => ygId($gcse, 'y9')]);
    $legacy = TutorSubject::factory()->create(['tutor_profile_id' => $tutor->id, 'curriculum_id' => $gcse->id, 'subject_id' => Subject::factory()->create()->id, 'level_min_id' => null, 'level_max_id' => null]);
    $legacy->forceFill(['level_min_legacy' => 'Reception', 'level_max_legacy' => 'Year Six'])->save();

    $card = app(TutorPresenter::class)->card($tutor->fresh(['user', 'tutorSubjects.levelMin', 'tutorSubjects.levelMax', 'tutorSubjects.curriculum', 'tutorSubjects.subject']), []);

    expect(collect($card['subjects'])->map(fn ($s) => [$s['level_min'], $s['level_max']])->all())
        ->toEqualCanonicalizing([['Year 7', 'Year 9'], ['Reception', 'Year Six']]);
});
