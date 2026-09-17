<?php

use App\Http\Controllers\Auth\TutorRegisteredUserController;
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
    Route::inertia('tutor/onboarding', 'TutorOnboarding')->name('tutor.onboarding');
});

require __DIR__.'/settings.php';
