<?php

use App\Enums\CurriculumCode;
use App\Enums\LevelTier;
use App\Models\Curriculum;
use App\Models\DocumentType;
use App\Models\PriceBand;
use App\Models\Subject;
use App\Models\User;
use App\Models\YearGroup;
use App\Support\YearGroups\YearGroupDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A tutor who has completed every onboarding step before the agreement (shared
 * by the onboarding and pages tests).
 */
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

    $curriculum = Curriculum::query()->where('code', CurriculumCode::Gcse)->first()
        ?? Curriculum::factory()->create(['code' => CurriculumCode::Gcse]);
    $subject = Subject::factory()->create();
    PriceBand::query()->firstOrCreate([
        'curriculum_id' => $curriculum->id,
        'level_tier' => LevelTier::Exam1,
        'effective_from' => now()->subYear()->toDateString(),
    ], [
        'min_rate' => 10000,
        'max_rate' => 20000,
    ]);
    test()->actingAs($tutor)->post(route('tutor.onboarding.subjects'), [
        'subjects' => [ygRow($curriculum, $subject, 'y10', 'y11')],
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

/**
 * A tutor-subject row for the onboarding POST: the year groups are looked up by
 * their default code (found or created, so it can be called repeatedly) — e.g.
 * `ygRow($gcse, $subject, 'y10', 'y11')` is Year 10 to Year 11 (R33).
 *
 * @return array{curriculum_id: int, subject_id: int, level_min_id: int, level_max_id: int}
 */
function ygRow(Curriculum $curriculum, Subject $subject, string $from, string $to): array
{
    $defaults = collect(YearGroupDefaults::all()[$curriculum->code->value]);
    $ids = [];

    foreach (['level_min_id' => $from, 'level_max_id' => $to] as $key => $code) {
        $definition = $defaults->firstWhere('code', $code);

        $ids[$key] = YearGroup::query()->firstOrCreate(
            ['curriculum_id' => $curriculum->id, 'code' => $code],
            ['label' => $definition['label'], 'sort' => $definition['sort'], 'level_tier' => $definition['tier']],
        )->id;
    }

    return ['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, ...$ids];
}
