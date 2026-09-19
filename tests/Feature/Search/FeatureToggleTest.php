<?php

use App\Enums\SettingGroup;
use App\Models\TutorProfile;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_feature-probe/{feature}', fn () => 'ok')->middleware(['web', 'feature:reviews'])->where('feature', 'reviews');
    Route::get('/_feature-probe-messaging', fn () => 'ok')->middleware(['web', 'feature:messaging']);
    Route::get('/_feature-probe-match', fn () => 'ok')->middleware(['web', 'feature:match_requests']);
});

it('answers 404 while a feature is off and 200 the moment it is on (R30 #9)', function (string $feature, string $url) {
    Settings::set($feature, true, SettingGroup::Features);
    test()->get($url)->assertOk();

    Settings::set($feature, false, SettingGroup::Features);
    test()->get($url)->assertNotFound();

    Settings::set($feature, true, SettingGroup::Features);
    test()->get($url)->assertOk();
})->with([
    'reviews' => ['reviews', '/_feature-probe/reviews'],
    'messaging' => ['messaging', '/_feature-probe-messaging'],
    'match requests' => ['match_requests', '/_feature-probe-match'],
]);

it('shares the feature map with every page', function () {
    Settings::set('messaging', false, SettingGroup::Features);

    test()->get('/login')->assertInertia(fn ($page) => $page
        ->where('features.reviews', true)
        ->where('features.messaging', false)
        ->where('features.match_requests', true));
});

it('drops the reviews block from the profile when the toggle is off (R30 #9)', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $tutor->user->forceFill(['name' => 'Layla Hassan'])->save();

    Settings::set('reviews', true, SettingGroup::Features);
    test()->get(route('tutors.show', $tutor->id))->assertInertia(fn ($page) => $page->has('tutor.reviews'));

    Settings::set('reviews', false, SettingGroup::Features);
    test()->get(route('tutors.show', $tutor->id))->assertInertia(fn ($page) => $page->missing('tutor.reviews'));
});

it('computes the trial price in one place from the frozen-at-read discount', function () {
    $tutor = TutorProfile::factory()->make(['hourly_rate' => 10000]);
    expect($tutor->trialPrice()->toFils())->toBe(5000);

    Settings::set('trial_discount_pct', 0);
    expect($tutor->trialPrice()->toFils())->toBe(10000);

    Settings::set('trial_discount_pct', 100);
    expect($tutor->trialPrice()->toFils())->toBe(0);

    expect(TutorProfile::factory()->make(['hourly_rate' => null])->trialPrice())->toBeNull();
    // Odd fils: the discount is rounded half-up, never a float.
    Settings::set('trial_discount_pct', 50);
    expect(TutorProfile::factory()->make(['hourly_rate' => 10001])->trialPrice()->toFils())->toBe(5000);
});
