<?php

use App\Enums\BudgetTier;
use App\Filament\Resources\ContentBlocks\Pages\EditContentBlock;
use App\Filament\Resources\MatchRequests\Pages\ViewMatchRequest;
use App\Models\ContentBlock;
use App\Models\Learner;
use App\Models\MatchRequest;
use App\Models\User;
use App\Support\BudgetTierLabels;
use Database\Seeders\ContentBlockSeeder;
use Livewire\Livewire;

// R35: budget-tier labels are content blocks (R30 #6, #15).
beforeEach(function () {
    test()->seed(ContentBlockSeeder::class);
    $this->admin = User::factory()->admin()->create();
});

/**
 * @return array<string, string>
 */
function budgetLabelsOnForm(User $parent): array
{
    $labels = [];
    test()->actingAs($parent)->get(route('match-requests.create'))->assertOk()->assertInertia(function ($page) use (&$labels) {
        $labels = collect($page->toArray()['props']['budgetTiers'])->pluck('label', 'value')->all();
    });

    return $labels;
}

it('seeds the three labels with the original wording', function () {
    expect(BudgetTierLabels::all())->toBe(['low' => 'Budget-friendly', 'mid' => 'Mid-range', 'high' => 'Premium']);
});

it('shows an edited label on the parent form and in the admin view, and keeps the stored tier (R30 #6)', function () {
    $parent = User::factory()->create();
    $request = MatchRequest::factory()->create(['account_user_id' => $parent->id, 'budget_tier' => BudgetTier::Mid]);

    Livewire::actingAs($this->admin)
        ->test(EditContentBlock::class, ['record' => ContentBlock::query()->where('key', 'match_budget_mid')->firstOrFail()->getRouteKey()])
        ->assertFormSet(['title_value' => 'Mid-range'])
        ->fillForm(['title_value' => 'Standard'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(budgetLabelsOnForm($parent))->toBe(['low' => 'Budget-friendly', 'mid' => 'Standard', 'high' => 'Premium'])
        ->and($request->fresh()->budget_tier)->toBe(BudgetTier::Mid); // history keeps the value, not the words

    Livewire::actingAs($this->admin)
        ->test(ViewMatchRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Standard');
});

it('falls back to the built-in wording when a label block is missing or blank (R30 #15)', function () {
    $parent = User::factory()->create();
    Learner::factory()->create(['account_user_id' => $parent->id]);

    ContentBlock::query()->where('key', 'match_budget_low')->delete();
    ContentBlock::query()->where('key', 'match_budget_high')->update(['body' => '   ']);

    expect(budgetLabelsOnForm($parent))->toBe(['low' => 'Budget-friendly', 'mid' => 'Mid-range', 'high' => 'Premium']);
});

it('does not overwrite an edited label on a re-seed', function () {
    ContentBlock::query()->where('key', 'match_budget_high')->update(['body' => 'Top tier']);

    test()->seed(ContentBlockSeeder::class);

    expect(BudgetTierLabels::label(BudgetTier::High))->toBe('Top tier');
});
