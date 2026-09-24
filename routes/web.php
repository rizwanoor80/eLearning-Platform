<?php

use App\Http\Controllers\Auth\TutorRegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Learner\LearnerController;
use App\Http\Controllers\Lessons\CancelLessonController;
use App\Http\Controllers\Match\MatchRequestController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\Tutor\TutorDashboardController;
use App\Http\Controllers\Tutor\TutorDocumentController;
use App\Http\Controllers\Tutor\TutorOnboardingController;
use App\Http\Controllers\Tutor\TutorProfileController;
use App\Http\Controllers\Tutor\TutorSearchController;
use App\Support\PublicPages;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

foreach (PublicPages::all() as $path => $page) {
    Route::get($path, PageController::class)->defaults('slug', $page['slug'])->name('pages.'.$page['slug']);
}

Route::get('tutors', TutorSearchController::class)->name('tutors.index');
Route::get('tutors/{tutor}', TutorProfileController::class)->where('tutor', '[0-9]{1,18}')->name('tutors.show');

Route::middleware(['auth', 'verified', 'can:access-parent-area'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');
    Route::post('lessons/{lesson}/cancel', CancelLessonController::class)->name('lessons.cancel');

    Route::resource('learners', LearnerController::class)->except('show');

    Route::middleware('feature:match_requests')->group(function () {
        Route::get('match-requests', [MatchRequestController::class, 'index'])->name('match-requests.index');
        Route::get('match-requests/create', [MatchRequestController::class, 'create'])->name('match-requests.create');
        Route::post('match-requests', [MatchRequestController::class, 'store'])->name('match-requests.store');
    });
});

Route::middleware('guest')->group(function () {
    Route::get('tutor/register', [TutorRegisteredUserController::class, 'create'])
        ->name('tutor.register');

    Route::post('tutor/register', [TutorRegisteredUserController::class, 'store'])
        ->name('tutor.register.store');
});

Route::middleware(['auth', 'verified', 'can:access-tutor-area'])->group(function () {
    Route::get('tutor/dashboard', [TutorDashboardController::class, 'show'])->name('tutor.dashboard');

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
