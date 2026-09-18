<?php

use App\Http\Controllers\Auth\TutorRegisteredUserController;
use App\Http\Controllers\Tutor\TutorDocumentController;
use App\Http\Controllers\Tutor\TutorOnboardingController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified', 'can:access-parent-area'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware('guest')->group(function () {
    Route::get('tutor/register', [TutorRegisteredUserController::class, 'create'])
        ->name('tutor.register');

    Route::post('tutor/register', [TutorRegisteredUserController::class, 'store'])
        ->name('tutor.register.store');
});

Route::middleware(['auth', 'verified', 'can:access-tutor-area'])->group(function () {
    Route::get('tutor/onboarding', [TutorOnboardingController::class, 'show'])->name('tutor.onboarding');
    Route::post('tutor/onboarding/personal', [TutorOnboardingController::class, 'storePersonal'])->name('tutor.onboarding.personal');
    Route::post('tutor/onboarding/permit', [TutorOnboardingController::class, 'storePermit'])->name('tutor.onboarding.permit');
    Route::post('tutor/onboarding/documents', [TutorOnboardingController::class, 'storeDocument'])->name('tutor.onboarding.documents');
    Route::post('tutor/onboarding/bank', [TutorOnboardingController::class, 'storeBank'])->name('tutor.onboarding.bank');
    Route::post('tutor/onboarding/subjects', [TutorOnboardingController::class, 'storeSubjects'])->name('tutor.onboarding.subjects');
    Route::post('tutor/onboarding/rate', [TutorOnboardingController::class, 'storeRate'])->name('tutor.onboarding.rate');
    Route::post('tutor/onboarding/profile', [TutorOnboardingController::class, 'storeProfile'])->name('tutor.onboarding.profile');
    Route::post('tutor/onboarding/availability', [TutorOnboardingController::class, 'storeAvailability'])->name('tutor.onboarding.availability');
    Route::post('tutor/onboarding/agreement', [TutorOnboardingController::class, 'storeAgreement'])->name('tutor.onboarding.agreement');
    Route::post('tutor/onboarding/complete', [TutorOnboardingController::class, 'complete'])->name('tutor.onboarding.complete');

    Route::get('tutor/documents/{document}', [TutorDocumentController::class, 'show'])
        ->middleware('signed')
        ->name('tutor.documents.show');
});

Route::middleware(['auth', 'verified', 'can:access-admin-area', 'signed'])->group(function () {
    Route::get('admin/documents/{document}', [TutorDocumentController::class, 'show'])
        ->name('admin.documents.show');
});

require __DIR__.'/settings.php';
