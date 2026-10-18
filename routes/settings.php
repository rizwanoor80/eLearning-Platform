<?php

use App\Http\Controllers\Settings\DisplayNameController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'throttle:account-writes'])->group(function () {
    // What Route::redirect() registers, minus its any(): the redirect is a page, not a write (R226's route walk would otherwise list five unthrottled methods).
    Route::get('settings', RedirectController::class)->defaults('destination', '/settings/profile')->defaults('status', 302);

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified', 'can:access-tutor-area', 'throttle:display-name'])->group(function () {
    Route::post('settings/display-name', [DisplayNameController::class, 'store'])->name('display-name.store');
});

Route::middleware(['auth', 'verified', 'throttle:account-writes'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
