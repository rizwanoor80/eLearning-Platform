<?php

use App\Models\Learner;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Parents\ParentGetStarted;
use Illuminate\Support\Facades\URL;

/**
 * R186: a parent's "Get started" page and the dashboard reminder banner. Ticks read the same rows
 * the booking flow reads; everything is addressed to the parent's account, never a learner.
 */
function gsGroups(User $parent): array
{
    return collect(app(ParentGetStarted::class)->groups($parent))->keyBy('key')->all();
}

function gsDone(array $group, string $item): bool
{
    return collect($group['items'])->firstWhere('key', $item)['done'];
}

it('lists the three groups, required first, with the learner as the only thing needed to book', function () {
    $parent = User::factory()->create();
    $groups = gsGroups($parent);

    expect(array_keys($groups))->toBe(['required', 'booking', 'optional'])
        ->and($groups['required']['required'])->toBeTrue()
        ->and($groups['booking']['required'])->toBeTrue()
        ->and($groups['optional']['required'])->toBeFalse()
        ->and(collect($groups['required']['items'])->pluck('key')->all())->toBe(['learner'])
        ->and(collect($groups['booking']['items'])->pluck('key')->all())->toBe(['card'])
        ->and(collect($groups['optional']['items'])->pluck('key')->all())->toBe(['details', 'more_learners']);
});

it('ticks the learner, card, details and extra-learner items from real rows', function () {
    $parent = User::factory()->create();
    expect(gsDone(gsGroups($parent)['required'], 'learner'))->toBeFalse();

    $first = Learner::factory()->create(['account_user_id' => $parent->id, 'school' => null, 'notes' => null]);
    $groups = gsGroups($parent);
    expect(gsDone($groups['required'], 'learner'))->toBeTrue()
        ->and(gsDone($groups['optional'], 'details'))->toBeFalse()
        ->and(gsDone($groups['optional'], 'more_learners'))->toBeFalse();

    $first->update(['school' => 'Test School', 'notes' => 'Needs help with fractions']);
    expect(gsDone(gsGroups($parent)['optional'], 'details'))->toBeTrue();

    Learner::factory()->create(['account_user_id' => $parent->id]);
    expect(gsDone(gsGroups($parent)['optional'], 'more_learners'))->toBeTrue();

    expect(gsDone(gsGroups($parent)['booking'], 'card'))->toBeFalse();
    PaymentMethod::factory()->create(['account_user_id' => $parent->id]);
    expect(gsDone(gsGroups($parent)['booking'], 'card'))->toBeTrue();
});

it('does not count another parent\'s learners or card', function () {
    $parent = User::factory()->create();
    $other = User::factory()->create();
    Learner::factory()->create(['account_user_id' => $other->id]);
    PaymentMethod::factory()->create(['account_user_id' => $other->id]);

    $groups = gsGroups($parent);

    expect(gsDone($groups['required'], 'learner'))->toBeFalse()
        ->and(gsDone($groups['booking'], 'card'))->toBeFalse()
        ->and(app(ParentGetStarted::class)->needsLearner($parent))->toBeTrue();
});

it('renders the page for a parent', function () {
    $parent = User::factory()->create();

    test()->actingAs($parent)->get(route('get-started'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('GetStarted')
            ->has('checklist', 3)
            ->where('checklist.0.items.0.key', 'learner')
            ->where('checklist.0.items.0.href', route('learners.create')));
});

it('keeps the page to the parent area and to signed-in users', function () {
    test()->get(route('get-started'))->assertRedirect(route('login'));

    test()->actingAs(User::factory()->tutor()->create())->get(route('get-started'))->assertForbidden();
});

it('lands a newly verified parent with no learner on Get started', function () {
    $parent = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $parent->id, 'hash' => sha1($parent->email)]);

    test()->actingAs($parent)->get($url)->assertRedirect(route('get-started', absolute: false).'?verified=1');

    expect($parent->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('lands a verified parent who already has a learner on the dashboard', function () {
    $parent = User::factory()->unverified()->create();
    Learner::factory()->create(['account_user_id' => $parent->id]);
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $parent->id, 'hash' => sha1($parent->email)]);

    test()->actingAs($parent)->get($url)->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

it('lands a verified tutor on onboarding, not Get started', function () {
    $tutor = User::factory()->tutor()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $tutor->id, 'hash' => sha1($tutor->email)]);

    test()->actingAs($tutor)->get($url)->assertRedirect(route('tutor.onboarding', absolute: false).'?verified=1');
});

it('shows the dashboard banner only until a learner exists', function () {
    $parent = User::factory()->create();

    test()->actingAs($parent)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->component('Dashboard')->where('needsLearner', true));

    Learner::factory()->create(['account_user_id' => $parent->id]);

    test()->actingAs($parent->fresh())->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('needsLearner', false));
});
