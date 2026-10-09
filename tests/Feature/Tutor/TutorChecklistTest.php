<?php

use App\Enums\TutorProfileStatus;
use App\Filament\Resources\TutorProfiles\Pages\ViewTutorProfile;
use App\Models\AvailabilityRule;
use App\Models\DocumentType;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Tutors\TutorOnboardingChecklist;
use App\Services\Tutors\TutorSubmissionReadiness;
use Livewire\Livewire;

/**
 * R186: the three-group checklist. The ticks come from the same checks the server enforces, so a
 * tick can never say "done" where submission, approval or search would refuse (R190 target).
 */
function clGroups(TutorProfile $profile): array
{
    return collect(app(TutorOnboardingChecklist::class)->groups($profile->user, $profile))->keyBy('key')->all();
}

function clDone(array $group, string $item): bool
{
    return collect($group['items'])->firstWhere('key', $item)['done'];
}

function clDraft(array $attributes = []): TutorProfile
{
    return TutorProfile::factory()->create(array_merge([
        'status' => TutorProfileStatus::Draft,
        'country' => null,
        'linkedin_url' => null,
        'agreement_accepted_at' => null,
        'headline' => null,
        'bio' => null,
        'hourly_rate' => null,
        'permit_number' => null,
        'permit_expires_at' => null,
        'bank_name' => null,
        'bank_iban' => null,
    ], $attributes));
}

it('returns the three groups in order, the first two required and the last optional', function () {
    $groups = clGroups(clDraft());

    expect(array_keys($groups))->toBe(['submit', 'search', 'optional'])
        ->and($groups['submit']['required'])->toBeTrue()
        ->and($groups['search']['required'])->toBeTrue()
        ->and($groups['optional']['required'])->toBeFalse()
        ->and(collect($groups['submit']['items'])->pluck('key')->all())->toBe(['contact', 'cv_or_linkedin', 'agreement'])
        ->and(collect($groups['search']['items'])->pluck('key')->all())->toBe(['subjects', 'rate', 'availability']);
});

it('ticks the submit group item by item as the profile fills in', function () {
    $profile = clDraft();
    expect(clGroups($profile)['submit']['complete'])->toBeFalse();

    $profile->update(['country' => 'AE']);
    expect(clDone(clGroups($profile)['submit'], 'contact'))->toBeTrue()
        ->and(clDone(clGroups($profile)['submit'], 'cv_or_linkedin'))->toBeFalse();

    $profile->update(['linkedin_url' => 'https://www.linkedin.com/in/test-tutor']);
    expect(clDone(clGroups($profile)['submit'], 'cv_or_linkedin'))->toBeTrue();

    $profile->update(['agreement_accepted_at' => now(), 'agreement_version' => '1']);
    expect(clGroups($profile)['submit']['complete'])->toBeTrue();
});

it('agrees with TutorSubmissionReadiness: the submit group is complete exactly when nothing is missing', function (array $attributes) {
    $profile = clDraft($attributes);

    $missing = app(TutorSubmissionReadiness::class)->missing($profile->user, $profile);

    expect(clGroups($profile)['submit']['complete'])->toBe($missing === []);
})->with([
    'nothing' => [[]],
    'country only' => [['country' => 'AE']],
    'country and linkedin' => [['country' => 'AE', 'linkedin_url' => 'https://www.linkedin.com/in/test-tutor']],
    'everything' => [['country' => 'AE', 'linkedin_url' => 'https://www.linkedin.com/in/test-tutor', 'agreement_accepted_at' => '2026-10-01 00:00:00', 'agreement_version' => '1']],
]);

it('ticks the search group from subjects, a valid rate and an availability window', function () {
    $profile = clDraft();
    $search = clGroups($profile)['search'];
    expect($search['complete'])->toBeFalse()
        ->and(clDone($search, 'subjects'))->toBeFalse()
        ->and(clDone($search, 'rate'))->toBeFalse()
        ->and(clDone($search, 'availability'))->toBeFalse();

    TutorProfile::factory()->makeApprovable($profile);
    $profile->availabilityRules()->delete();
    $search = clGroups($profile->fresh())['search'];
    expect(clDone($search, 'subjects'))->toBeTrue()
        ->and(clDone($search, 'rate'))->toBeTrue()
        ->and(clDone($search, 'availability'))->toBeFalse()
        ->and($search['complete'])->toBeFalse();

    AvailabilityRule::factory()->create(['tutor_profile_id' => $profile->id]);
    expect(clGroups($profile->fresh())['search']['complete'])->toBeTrue();
});

it('does not tick the rate when it sits outside the current band', function () {
    $profile = clDraft();
    TutorProfile::factory()->makeApprovable($profile);
    $profile->forceFill(['hourly_rate' => 999999])->save();

    expect(clDone(clGroups($profile->fresh())['search'], 'rate'))->toBeFalse();
});

it('adds the required-documents and permit items only when they apply', function () {
    $profile = clDraft(['permit_expires_at' => now()->addYear()]);
    $keys = fn () => collect(clGroups($profile->fresh())['search']['items'])->pluck('key')->all();

    DocumentType::query()->update(['required' => false]);
    expect($keys())->not->toContain('required_documents')->not->toContain('permit_valid');

    DocumentType::query()->active()->first()?->update(['required' => true]);
    if (DocumentType::query()->active()->where('required', true)->exists()) {
        expect($keys())->toContain('required_documents');
    }

    $profile->update(['permit_expires_at' => now()->subDay()]);
    expect($keys())->toContain('permit_valid')
        ->and(clDone(clGroups($profile->fresh())['search'], 'permit_valid'))->toBeFalse();
});

it('treats the optional group as optional: nothing in it gates submission or search', function () {
    $profile = clDraft(['country' => 'AE', 'linkedin_url' => 'https://www.linkedin.com/in/test-tutor', 'agreement_accepted_at' => now(), 'agreement_version' => '1']);
    $groups = clGroups($profile);

    expect($groups['submit']['complete'])->toBeTrue()
        ->and($groups['optional']['complete'])->toBeFalse()
        ->and(app(TutorSubmissionReadiness::class)->missing($profile->user, $profile))->toBe([]);
});

it('lets a profile be saved without a headline or bio', function () {
    $profile = clDraft();

    test()->actingAs($profile->user)
        ->post(route('tutor.onboarding.profile'), ['headline' => '', 'bio' => '', 'intro_video_url' => '', 'min_lead_hours' => ''])
        ->assertSessionHasNoErrors();
});

it('shares the checklist on the onboarding page and the rate band with its level', function () {
    $profile = clDraft();
    TutorProfile::factory()->makeApprovable($profile);

    test()->actingAs($profile->user)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->component('tutor/Onboarding')
            ->has('checklist', 3)
            ->where('checklist.0.key', 'submit')
            ->where('rateBand.level', 'Lower secondary'));
});

it('sends no rate band when no subject is chosen', function () {
    $profile = clDraft();

    test()->actingAs($profile->user)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('rateBand', null));
});

it('shows the dashboard the two required groups until both are done, then hides it', function () {
    $profile = clDraft(['status' => TutorProfileStatus::Draft]);

    test()->actingAs($profile->user)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->component('tutor/Dashboard')
            ->has('checklist', 2)
            ->where('checklist.0.key', 'submit')
            ->where('checklist.1.key', 'search'));

    $profile->update(['country' => 'AE', 'linkedin_url' => 'https://www.linkedin.com/in/test-tutor', 'agreement_accepted_at' => now(), 'agreement_version' => '1', 'status' => TutorProfileStatus::Approved]);
    TutorProfile::factory()->makeApprovable($profile);

    // A fresh user: the first request cached the (then incomplete) profile on the acting instance.
    test()->actingAs($profile->user->fresh())->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->where('checklist', null));
});

it('shows a tutor with no profile row yet the full required checklist', function () {
    $tutor = User::factory()->tutor()->create();

    test()->actingAs($tutor)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->has('checklist', 2));

    expect($tutor->tutorProfile()->exists())->toBeFalse();
});

it('hides the dashboard checklist from a rejected or suspended tutor', function (TutorProfileStatus $status) {
    $profile = clDraft(['status' => $status]);

    test()->actingAs($profile->user)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->where('checklist', null));
})->with([[TutorProfileStatus::Rejected], [TutorProfileStatus::Suspended]]);

it('shows an approved tutor without availability the search group still open', function () {
    $profile = TutorProfile::factory()->approved()->create(['country' => 'AE', 'linkedin_url' => 'https://www.linkedin.com/in/test-tutor', 'agreement_accepted_at' => now(), 'agreement_version' => '1']);
    TutorProfile::factory()->makeApprovable($profile);
    $profile->availabilityRules()->delete();

    test()->actingAs($profile->user)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->has('checklist', 2)
            ->where('checklist.1.complete', false)
            ->where('checklist.1.items.2.key', 'availability')
            ->where('checklist.1.items.2.done', false));
});

it('shows the admin review page the same three groups and the parent-facing name', function () {
    $admin = User::factory()->admin()->create();
    $profile = clDraft(['country' => 'AE', 'user_id' => User::factory()->tutor()->create(['name' => 'Amira Haddad'])]);
    $profile->update(['display_name' => 'Miss Amira']);

    Livewire::actingAs($admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->assertOk()
        ->assertSee('To submit for review')
        ->assertSee('To appear in search')
        ->assertSee('Optional')
        ->assertSee('✓ Contact details')
        ->assertSee('✗ CV or LinkedIn profile')
        ->assertSee('Miss Amira');
});
