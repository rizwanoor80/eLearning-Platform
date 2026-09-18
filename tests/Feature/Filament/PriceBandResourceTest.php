<?php

use App\Enums\CurriculumCode;
use App\Enums\LevelTier;
use App\Filament\Resources\PriceBands\Pages\CreatePriceBand;
use App\Filament\Resources\PriceBands\Pages\EditPriceBand;
use App\Filament\Resources\PriceBands\Pages\ListPriceBands;
use App\Models\Curriculum;
use App\Models\PriceBand;
use App\Models\User;
use App\Services\Pricing\PriceBandOverlapCheck;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->gcse = Curriculum::factory()->create(['code' => CurriculumCode::Gcse, 'name' => 'GCSE']);
    $this->myp = Curriculum::factory()->create(['code' => CurriculumCode::IbMyp, 'name' => 'IB MYP']);
});

function bandFor(Curriculum $curriculum, int $min, int $max, string $from = '2025-01-01'): PriceBand
{
    return PriceBand::factory()->create([
        'curriculum_id' => $curriculum->id,
        'level_tier' => LevelTier::Exam1,
        'min_rate' => $min,
        'max_rate' => $max,
        'effective_from' => $from,
    ]);
}

it('lists price bands with rates shown as money', function () {
    $band = bandFor($this->gcse, 10000, 20000);

    Livewire::actingAs($this->admin)
        ->test(ListPriceBands::class)
        ->assertCanSeeTableRecords([$band])
        ->assertSee('100.00');
});

it('refuses a non-admin access to the price bands resource', function () {
    test()->actingAs(User::factory()->tutor()->create())->get(ListPriceBands::getUrl())->assertForbidden();
});

it('edits a band in whole fils', function () {
    $band = bandFor($this->gcse, 10000, 20000);

    Livewire::actingAs($this->admin)
        ->test(EditPriceBand::class, ['record' => $band->getRouteKey()])
        ->fillForm(['min_rate' => 11000, 'max_rate' => 21000])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($band->fresh()->min_rate->toFils())->toBe(11000)
        ->and($band->fresh()->max_rate->toFils())->toBe(21000);
});

it('rejects a maximum below the minimum', function () {
    $band = bandFor($this->gcse, 10000, 20000);

    Livewire::actingAs($this->admin)
        ->test(EditPriceBand::class, ['record' => $band->getRouteKey()])
        ->fillForm(['min_rate' => 30000, 'max_rate' => 20000])
        ->call('save')
        ->assertHasFormErrors(['max_rate']);
});

it('warns, without blocking the save, when two curricula\'s bands at a tier stop overlapping', function () {
    bandFor($this->gcse, 10000, 20000);

    Livewire::actingAs($this->admin)
        ->test(CreatePriceBand::class)
        ->fillForm([
            'curriculum_id' => $this->myp->id,
            'level_tier' => LevelTier::Exam1->value,
            'min_rate' => 25000,
            'max_rate' => 30000,
            'effective_from' => '2025-01-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Price bands do not overlap');

    expect(PriceBand::query()->where('curriculum_id', $this->myp->id)->exists())->toBeTrue();
});

it('does not warn when the bands overlap', function () {
    bandFor($this->gcse, 10000, 20000);

    Livewire::actingAs($this->admin)
        ->test(CreatePriceBand::class)
        ->fillForm([
            'curriculum_id' => $this->myp->id,
            'level_tier' => LevelTier::Exam1->value,
            'min_rate' => 15000,
            'max_rate' => 30000,
            'effective_from' => '2025-01-01',
        ])
        ->call('create')
        ->assertNotNotified('Price bands do not overlap');
});

it('judges overlap on each curriculum\'s current band, not superseded or future ones', function () {
    $check = app(PriceBandOverlapCheck::class);

    bandFor($this->gcse, 10000, 20000, '2020-01-01');
    bandFor($this->gcse, 10000, 20000, '2025-01-01');
    bandFor($this->myp, 25000, 30000, '2020-01-01');           // superseded (non-overlapping)…
    bandFor($this->myp, 15000, 30000, '2025-01-01');           // …by a current overlapping band
    bandFor($this->myp, 90000, 99000, now()->addYear()->toDateString()); // future, ignored

    expect($check->conflictingCurricula(LevelTier::Exam1))->toBe([]);

    bandFor($this->myp, 25000, 30000, now()->toDateString());   // now current, and disjoint from GCSE

    expect($check->conflictingCurricula(LevelTier::Exam1))->toContain('GCSE')->toContain('IB MYP');
});
