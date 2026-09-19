<?php

use App\Enums\CurriculumCode;
use App\Enums\LevelTier;
use App\Models\Curriculum;
use App\Models\TutorSubject;
use App\Models\User;
use App\Models\YearGroup;
use App\Support\YearGroups\LegacyYearGroupMapper;
use App\Support\YearGroups\YearGroupDefaults;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\YearGroupSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const YG_MIGRATION = 'database/migrations/2026_09_20_100000_create_year_groups_and_convert_columns.php';

// ---- the defaults and the seeder (R30 #9, #18) ---------------------------------------------------

it('seeds 21 year groups from one source, each with a tier its curriculum has (R30 #18)', function () {
    test()->seed([CurriculumSeeder::class, YearGroupSeeder::class]);

    expect(YearGroup::query()->count())->toBe(21);

    $byCurriculum = YearGroup::query()->with('curriculum')->get()->groupBy(fn (YearGroup $g) => $g->curriculum->code->value);
    expect($byCurriculum->map->count()->all())->toBe(['GCSE' => 5, 'A_LEVEL' => 2, 'IB_MYP' => 5, 'IB_DP' => 2, 'CBSE' => 7]);

    foreach (YearGroup::query()->with('curriculum')->get() as $group) {
        expect($group->curriculum->code->tiers())->toContain($group->level_tier);
    }

    // Spot-check PRD §3's examples: Year 9 lower secondary, Year 10 exam 1, CBSE 11 exam 2.
    $tier = fn (string $curriculum, string $code) => YearGroup::query()->whereHas('curriculum', fn ($q) => $q->where('code', $curriculum))->where('code', $code)->firstOrFail()->level_tier;
    expect($tier('GCSE', 'y9'))->toBe(LevelTier::LowerSecondary)
        ->and($tier('GCSE', 'y10'))->toBe(LevelTier::Exam1)
        ->and($tier('IB_MYP', 'myp4'))->toBe(LevelTier::Exam1)
        ->and($tier('CBSE', 'g11'))->toBe(LevelTier::Exam2);
});

it('never overwrites an admin’s change on a re-seed, and adds only what is missing (R30 #18)', function () {
    test()->seed([CurriculumSeeder::class, YearGroupSeeder::class]);
    $year = YearGroup::query()->where('code', 'y8')->firstOrFail();
    $year->update(['label' => 'Grade 8 (renamed)', 'level_tier' => LevelTier::Exam1]);
    YearGroup::query()->where('code', 'y11')->whereHas('curriculum', fn ($q) => $q->where('code', 'GCSE'))->delete();

    test()->seed(YearGroupSeeder::class);

    expect($year->fresh()->label)->toBe('Grade 8 (renamed)')->and($year->fresh()->level_tier)->toBe(LevelTier::Exam1)
        ->and(YearGroup::query()->count())->toBe(21); // the deleted default came back, nothing else changed
});

it('lists every default with a unique code and label per curriculum', function () {
    foreach (YearGroupDefaults::all() as $rows) {
        expect(collect($rows)->pluck('code')->unique())->toHaveCount(count($rows))
            ->and(collect($rows)->pluck('label')->unique())->toHaveCount(count($rows))
            ->and(collect($rows)->pluck('sort')->all())->toBe(range(1, count($rows)));
    }
});

// ---- the mapping, through the real migration (R30 #8) ----------------------------------------------

it('maps legacy free text through the real migration: rollback, seed legacy rows, migrate again', function () {
    test()->seed([CurriculumSeeder::class, YearGroupSeeder::class]);
    $gcse = Curriculum::query()->where('code', CurriculumCode::Gcse)->firstOrFail();
    $parent = User::factory()->create(['name' => 'Very Private Parent']);
    $tutorUser = User::factory()->tutor()->create(['name' => 'Very Private Tutor']);
    $tutor = DB::table('tutor_profiles')->insertGetId(['user_id' => $tutorUser->id, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
    $subjectId = DB::table('subjects')->insertGetId(['name' => 'Maths', 'slug' => 'maths', 'sort' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $subjectTwo = DB::table('subjects')->insertGetId(['name' => 'Physics', 'slug' => 'physics', 'sort' => 2, 'created_at' => now(), 'updated_at' => now()]);

    // A learner already on the new structure: the rollback must turn it back into words.
    $structured = DB::table('learners')->insertGetId(['account_user_id' => $parent->id, 'display_name' => 'Structured', 'is_minor' => true, 'curriculum_id' => $gcse->id,
        'year_group_id' => YearGroup::query()->where('curriculum_id', $gcse->id)->where('code', 'y9')->value('id'), 'created_at' => now(), 'updated_at' => now()]);

    // `--step` counts the newest migrations of ALL paths before the path filter applies, so it must
    // cover every migration added after this one (later steps add their own).
    $newer = DB::table('migrations')->where('migration', '>=', '2026_09_20_100000_create_year_groups_and_convert_columns')->count();
    Artisan::call('migrate:rollback', ['--path' => YG_MIGRATION, '--step' => $newer, '--force' => true]);

    expect(Schema::hasTable('year_groups'))->toBeFalse()
        ->and(Schema::hasColumn('learners', 'year_group'))->toBeTrue()
        ->and(DB::table('learners')->where('id', $structured)->value('year_group'))->toBe('Year 9');

    $learner = fn (string $name, ?string $text, ?int $curriculum) => DB::table('learners')->insertGetId([
        'account_user_id' => $parent->id, 'display_name' => $name, 'is_minor' => true, 'curriculum_id' => $curriculum, 'year_group' => $text, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $exact = $learner('Exact', 'Year 8', $gcse->id);
    $sloppy = $learner('Sloppy', '  yEAR   10 ', $gcse->id);
    $unknown = $learner('Unknown', 'Reception', $gcse->id);
    $noCurriculum = $learner('NoCurriculum', 'Year 8', null);
    $blank = $learner('Blank', null, $gcse->id);

    $subject = fn (int $subject, string $min, string $max, string $tier) => DB::table('tutor_subjects')->insertGetId([
        'tutor_profile_id' => $tutor, 'curriculum_id' => $gcse->id, 'subject_id' => $subject, 'level_min' => $min, 'level_max' => $max, 'level_tier' => $tier, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $typedWrongly = $subject($subjectId, 'Year 7', 'Year 9', 'exam_1');   // the tier was typed; it is derived now
    $halfMatched = $subject($subjectTwo, 'Year 10', 'Sixth form', 'exam_1');

    Artisan::call('migrate', ['--path' => YG_MIGRATION, '--force' => true]);

    $ygId = fn (string $code) => YearGroup::query()->where('curriculum_id', $gcse->id)->where('code', $code)->value('id');

    // Learners: exact and trimmed/case-insensitive matches map and clear the legacy text; the rest keep it.
    expect(DB::table('learners')->where('id', $structured)->value('year_group_id'))->toBe($ygId('y9'))
        ->and(DB::table('learners')->where('id', $exact)->first())->toMatchObject(['year_group_id' => $ygId('y8'), 'year_group_legacy' => null])
        ->and(DB::table('learners')->where('id', $sloppy)->first())->toMatchObject(['year_group_id' => $ygId('y10'), 'year_group_legacy' => null])
        ->and(DB::table('learners')->where('id', $unknown)->first())->toMatchObject(['year_group_id' => null, 'year_group_legacy' => 'Reception'])
        ->and(DB::table('learners')->where('id', $noCurriculum)->first())->toMatchObject(['year_group_id' => null, 'year_group_legacy' => 'Year 8'])
        ->and(DB::table('learners')->where('id', $blank)->first())->toMatchObject(['year_group_id' => null, 'year_group_legacy' => null]);

    // Tutor subjects: each end is mapped on its own; the tier is re-derived from the range.
    $rows = TutorSubject::query()->whereKey([$typedWrongly, $halfMatched])->get()->keyBy('id');
    expect($rows[$typedWrongly]->level_min_id)->toBe($ygId('y7'))->and($rows[$typedWrongly]->level_max_id)->toBe($ygId('y9'))
        ->and($rows[$typedWrongly]->level_tier)->toBe(LevelTier::LowerSecondary)
        ->and($rows[$halfMatched]->level_min_id)->toBe($ygId('y10'))->and($rows[$halfMatched]->level_min_legacy)->toBeNull()
        ->and($rows[$halfMatched]->level_max_id)->toBeNull()->and($rows[$halfMatched]->level_max_legacy)->toBe('Sixth form')
        ->and($rows[$halfMatched]->isUnmapped())->toBeTrue();

    // Idempotent: a second run touches nothing that is already mapped.
    $again = (new LegacyYearGroupMapper)->run();
    expect($again['learners']['matched'])->toBe(0)->and($again['tutor_subjects']['matched'])->toBe(0)
        ->and($again['learners']['unmatched'])->toBe(2)->and($again['tutor_subjects']['unmatched'])->toBe(1);
});

it('reports unmatched rows without any names, and remaps on request (R30 #17)', function () {
    test()->seed([CurriculumSeeder::class, YearGroupSeeder::class]);
    $gcse = Curriculum::query()->where('code', CurriculumCode::Gcse)->firstOrFail();
    $parent = User::factory()->create(['name' => 'Very Private Parent']);
    DB::table('learners')->insert([
        ['account_user_id' => $parent->id, 'display_name' => 'Very Private Child', 'is_minor' => true, 'curriculum_id' => $gcse->id, 'year_group_id' => null, 'year_group_legacy' => 'Reception', 'created_at' => now(), 'updated_at' => now()],
        ['account_user_id' => $parent->id, 'display_name' => 'Another Child', 'is_minor' => true, 'curriculum_id' => $gcse->id, 'year_group_id' => null, 'year_group_legacy' => 'Year 8', 'created_at' => now(), 'updated_at' => now()],
    ]);

    Artisan::call('year-groups:report');
    $output = Artisan::output();

    expect($output)->toContain('Reception')->toContain('Year 8')->toContain('GCSE')->toContain('learners')
        ->not->toContain('Very Private')->not->toContain('Another Child')->not->toContain($parent->email);

    // "Year 8" only failed to map because it was inserted after the migration; --remap picks it up.
    Artisan::call('year-groups:report', ['--remap' => true]);
    expect(Artisan::output())->toContain('Remapped: learners 1 matched, 1 unmatched')->toContain('Reception')
        ->and(DB::table('learners')->whereNotNull('year_group_id')->count())->toBe(1);

    DB::table('learners')->where('year_group_legacy', 'Reception')->delete();
    Artisan::call('year-groups:report');
    expect(Artisan::output())->toContain('No unmatched year groups.');
});
