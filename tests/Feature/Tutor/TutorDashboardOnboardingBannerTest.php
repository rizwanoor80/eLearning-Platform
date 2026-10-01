<?php

use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;
use App\Models\User;

/**
 * R173(a): the "Complete your profile" dashboard banner, driven by
 * `TutorSubmissionReadiness::missing()` — the same list the onboarding
 * wizard's own step picker uses, never a second definition of what's missing.
 */
it('shows the banner with the full missing list for a tutor with no profile row at all', function () {
    $tutor = User::factory()->tutor()->create();

    test()->actingAs($tutor)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->component('tutor/Dashboard')
            ->where('onboarding.visible', true)
            ->where('onboarding.missing', ['your country', 'a CV or LinkedIn profile', 'accepting the tutor agreement']));

    expect($tutor->tutorProfile()->exists())->toBeFalse();
});

it('shows the banner with a shrinking missing list as a draft tutor completes steps', function () {
    $tutor = User::factory()->tutor()->create();
    TutorProfile::factory()->create([
        'user_id' => $tutor->id,
        'status' => TutorProfileStatus::Draft,
        'country' => 'AE',
        'linkedin_url' => 'https://www.linkedin.com/in/test-tutor',
        'agreement_accepted_at' => null,
    ]);

    test()->actingAs($tutor)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->component('tutor/Dashboard')
            ->where('onboarding.visible', true)
            ->where('onboarding.missing', ['accepting the tutor agreement']));
});

it('keeps the banner visible for a changes_requested tutor', function () {
    $tutor = User::factory()->tutor()->create();
    TutorProfile::factory()->create([
        'user_id' => $tutor->id,
        'status' => TutorProfileStatus::ChangesRequested,
        'country' => null,
    ]);

    test()->actingAs($tutor)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->component('tutor/Dashboard')
            ->where('onboarding.visible', true)
            ->where('onboarding.missing', ['your country', 'a CV or LinkedIn profile', 'accepting the tutor agreement']));
});

it('hides the banner once a tutor is pending_review, approved, suspended or rejected', function (TutorProfileStatus $status) {
    $tutor = User::factory()->tutor()->create();
    TutorProfile::factory()->create(['user_id' => $tutor->id, 'status' => $status, 'country' => null]);

    test()->actingAs($tutor)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->component('tutor/Dashboard')
            ->where('onboarding.visible', false)
            ->where('onboarding.missing', []));
})->with([
    'pending_review' => [TutorProfileStatus::PendingReview],
    'approved' => [TutorProfileStatus::Approved],
    'suspended' => [TutorProfileStatus::Suspended],
    'rejected' => [TutorProfileStatus::Rejected],
]);
