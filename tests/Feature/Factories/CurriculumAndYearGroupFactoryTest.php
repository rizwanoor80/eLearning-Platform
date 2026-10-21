<?php

use App\Enums\CurriculumCode;
use App\Models\Curriculum;
use App\Models\YearGroup;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\YearGroupSeeder;

/**
 * 24f (R264): the factories draw random codes and labels, and the seeders own `y7`..`y13`, `Year 7`.. and so on. A draw
 * that matched a seeded row failed a test at random (a unique violation); neither factory can match one now.
 */
it('never gives a year group a code or label the seeded defaults use', function () {
    $this->seed([CurriculumSeeder::class, YearGroupSeeder::class]);
    $seeded = YearGroup::query()->count();
    $curriculum = Curriculum::query()->where('code', CurriculumCode::Gcse->value)->firstOrFail();

    // The collision-proof part: the label is not of any form the defaults use, so no draw can ever match one.
    expect(YearGroup::factory()->make(['curriculum_id' => $curriculum->id])->label)->not->toMatch('/^(Year|MYP|DP|Grade) \d+$/')
        ->and(YearGroup::factory()->make(['curriculum_id' => $curriculum->id])->code)->not->toMatch('/^(y|myp|dp|g)\d+$/');

    // Far more than enough draws for a collision with the 30-odd seeded labels to show if it were possible.
    YearGroup::factory()->count(300)->create(['curriculum_id' => $curriculum->id]);

    expect(YearGroup::query()->count())->toBe($seeded + 300)
        ->and(YearGroup::query()->where('curriculum_id', $curriculum->id)->pluck('label')->unique()->count())
        ->toBe(YearGroup::query()->where('curriculum_id', $curriculum->id)->count());
});

it('draws curriculum codes that do not exist yet, one at a time or many at once, and says so when none is left', function () {
    Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => 'GCSE', 'sort' => 0]);

    Curriculum::factory()->count(2)->create();
    Curriculum::factory()->create();

    expect(Curriculum::query()->pluck('code')->map(fn ($code) => $code->value)->unique()->count())->toBe(4);

    Curriculum::factory()->create();

    expect(Curriculum::query()->count())->toBe(count(CurriculumCode::cases()))
        ->and(fn () => Curriculum::factory()->create())->toThrow(LogicException::class);
});
