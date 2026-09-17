<?php

use App\Enums\CurriculumCode;
use App\Enums\LevelTier;
use App\Enums\Role;
use App\Models\Curriculum;
use App\Models\DocumentType;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PriceBand;
use App\Models\Subject;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config([
        'seeding.admin.email' => 'admin@project-elearning.test',
        'seeding.admin.password' => 'a-strong-seed-password',
    ]);
});

it('seeds curricula, subjects, price bands, settings, document types, the tutor agreement page and one admin user', function () {
    $this->seed();

    expect(Curriculum::query()->count())->toBe(5)
        ->and(Subject::query()->count())->toBe(25)
        ->and(PriceBand::query()->count())->toBe(9)
        ->and(DB::table('settings')->count())->toBe(20)
        ->and(DocumentType::query()->count())->toBe(4)
        ->and(Page::query()->count())->toBe(1)
        ->and(PageVersion::query()->count())->toBe(1)
        ->and(User::query()->where('role', Role::Admin)->count())->toBe(1);
});

it('is idempotent — seeding twice does not change the row counts', function () {
    $this->seed();
    $this->seed();

    expect(Curriculum::query()->count())->toBe(5)
        ->and(Subject::query()->count())->toBe(25)
        ->and(PriceBand::query()->count())->toBe(9)
        ->and(DB::table('settings')->count())->toBe(20)
        ->and(DocumentType::query()->count())->toBe(4)
        ->and(Page::query()->count())->toBe(1)
        ->and(PageVersion::query()->count())->toBe(1)
        ->and(User::query()->where('role', Role::Admin)->count())->toBe(1);
});

it('applies the PRD §3 band to the correct curriculum and tier as Money', function () {
    $this->seed();

    $gcseExam1 = PriceBand::query()
        ->whereHas('curriculum', fn ($query) => $query->where('code', CurriculumCode::Gcse))
        ->where('level_tier', LevelTier::Exam1)
        ->firstOrFail();

    expect($gcseExam1->min_rate)->toBeInstanceOf(Money::class)
        ->and($gcseExam1->min_rate->equals(Money::fils(10000)))->toBeTrue()
        ->and($gcseExam1->max_rate->equals(Money::fils(20000)))->toBeTrue();
});

it('gives every curriculum only the tiers that exist for it', function () {
    $this->seed();

    $aLevel = Curriculum::query()->where('code', CurriculumCode::ALevel)->firstOrFail();
    $cbse = Curriculum::query()->where('code', CurriculumCode::Cbse)->firstOrFail();

    expect($aLevel->priceBands()->count())->toBe(1)
        ->and($cbse->priceBands()->count())->toBe(3);
});

it('refuses to seed the admin user when the credentials are missing', function () {
    config(['seeding.admin.email' => null, 'seeding.admin.password' => null]);

    (new AdminUserSeeder)->run();
})->throws(RuntimeException::class);
