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

// Helpers used by more than one test file live here, never in a test file: under
// `php artisan test --parallel` files are split across processes, so a file cannot rely on
// another file having been loaded (R128).

const FAKE_WEBHOOK_SECRET = 'test-fake-webhook-secret';

/**
 * @param  array<string, mixed>  $overrides
 */
function webhookBody(string $id = 'evt-1', array $overrides = []): string
{
    return json_encode(array_replace_recursive([
        'id' => $id,
        'type' => 'participant.joined',
        'event_ts' => time(),
        'payload' => ['room' => 'lesson-42', 'user_id' => 'tutor'],
    ], $overrides));
}

/**
 * @return array<string, string>
 */
function signedHeaders(string $body, ?int $timestamp = null, string $secret = FAKE_WEBHOOK_SECRET): array
{
    $timestamp ??= time();

    return [
        'X-Webhook-Timestamp' => (string) $timestamp,
        'X-Webhook-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ];
}

function postWebhook(string $code, string $body, array $headers)
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', "/webhooks/video/{$code}", [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);
}

/**
 * The attributes of a parent-added learner, with a year group of its own curriculum.
 *
 * @return array{display_name: string, year_group_id: int, curriculum_id: int}
 */
function lnChild(): array
{
    $curriculum = Curriculum::factory()->create();

    return ['display_name' => 'Kid', 'year_group_id' => YearGroup::factory()->create(['curriculum_id' => $curriculum->id])->id, 'curriculum_id' => $curriculum->id];
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
 * The shared GCSE curriculum row (found or created), matching LessonFactory's
 * own convention — a fresh, randomly coded Curriculum::factory() per Learner
 * (LearnerFactory's default) can collide with it on the unique `code` when a
 * test creates more than one Learner. Pin every extra Learner::factory() call
 * to this id.
 */
function gcseCurriculumId(): int
{
    return Curriculum::query()->firstOrCreate(
        ['code' => CurriculumCode::Gcse],
        ['name' => CurriculumCode::Gcse->value, 'sort' => 0],
    )->id;
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
