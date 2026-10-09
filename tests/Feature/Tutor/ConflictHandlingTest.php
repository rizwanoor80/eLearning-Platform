<?php

use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;
use Illuminate\Support\Facades\Route;

/**
 * R187(a): a conflict the app raises itself is a message on the same page, never an error page; any
 * other failure is left alone.
 */
it('answers a locked step with a redirect back and an error toast, not a 409 page', function () {
    $pending = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::PendingReview]);

    $response = test()->actingAs($pending->user)
        ->from(route('tutor.onboarding'))
        ->post(route('tutor.onboarding.rate'), ['hourly_rate' => '150.00']);

    $response->assertRedirect(route('tutor.onboarding'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error')
        ->assertSessionHas('inertia.flash_data.toast.message', fn ($m) => str_contains($m, 'review team'));
});

it('treats a double submit for review as a message on the page', function () {
    $pending = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::PendingReview]);

    test()->actingAs($pending->user)->from(route('tutor.onboarding'))
        ->post(route('tutor.onboarding.complete'))
        ->assertRedirect(route('tutor.onboarding'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Your profile has already been submitted for review.');
});

it('does not turn other http errors into a redirect', function () {
    Route::middleware('web')->post('/_test/forbidden', fn () => abort(403, 'No.'));
    Route::middleware('web')->post('/_test/missing', fn () => abort(404));
    Route::middleware('web')->post('/_test/boom', fn () => throw new RuntimeException('boom'));

    test()->post('/_test/forbidden')->assertForbidden();
    test()->post('/_test/missing')->assertNotFound();
    test()->post('/_test/boom')->assertServerError();
});

it('leaves a json 409 and a GET 409 as a real 409', function () {
    Route::middleware('web')->post('/_test/conflict', fn () => abort(409, 'Conflict.'));
    Route::middleware('web')->get('/_test/conflict', fn () => abort(409, 'Conflict.'));

    test()->postJson('/_test/conflict')->assertStatus(409);
    test()->get('/_test/conflict')->assertStatus(409);
});
