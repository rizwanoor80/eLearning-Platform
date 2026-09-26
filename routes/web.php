<?php

use App\Http\Controllers\Auth\TutorRegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Learner\LearnerController;
use App\Http\Controllers\Lessons\BookLessonController;
use App\Http\Controllers\Lessons\CancelLessonController;
use App\Http\Controllers\Lessons\LessonRoomController;
use App\Http\Controllers\Match\MatchRequestController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\Payments\TestCardController;
use App\Http\Controllers\Payments\WeeklySlotController;
use App\Http\Controllers\Tutor\TutorDashboardController;
use App\Http\Controllers\Tutor\TutorDocumentController;
use App\Http\Controllers\Tutor\TutorOnboardingController;
use App\Http\Controllers\Tutor\TutorProfileController;
use App\Http\Controllers\Tutor\TutorSearchController;
use App\Http\Controllers\Tutor\TutorWeeklySlotController;
use App\Http\Controllers\Webhooks\VideoWebhookController;
use App\Support\PublicPages;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

foreach (PublicPages::all() as $path => $page) {
    Route::get($path, PageController::class)->defaults('slug', $page['slug'])->name('pages.'.$page['slug']);
}

Route::post('webhooks/video/{code}', VideoWebhookController::class)->where('code', '[a-z]{2,16}')->middleware('throttle:video-webhooks')->name('webhooks.video');

Route::get('tutors', TutorSearchController::class)->name('tutors.index');
Route::get('tutors/{tutor}', TutorProfileController::class)->where('tutor', '[0-9]{1,18}')->name('tutors.show');

// CP6 7c: the lesson room, for the lesson's own tutor or parent. LessonPolicy::attend is the authorisation.
Route::middleware(['auth', 'verified'])->prefix('lessons/{lesson}')->where(['lesson' => '[0-9]{1,18}'])->group(function () {
    Route::post('room', [LessonRoomController::class, 'join'])->middleware('throttle:20,1')->name('lessons.room');
    Route::post('joined', [LessonRoomController::class, 'joined'])->name('lessons.joined');
    Route::post('no-show', [LessonRoomController::class, 'noShow'])->name('lessons.no-show');
});

Route::middleware(['auth', 'verified', 'can:access-parent-area'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');
    // R114: the single-booking screen. The GET is the parent's page for one chosen slot; a guest is sent to login and returned here.
    Route::get('tutors/{tutor}/book', [BookLessonController::class, 'create'])->where('tutor', '[0-9]{1,18}')->name('tutors.book');
    Route::post('lessons', [BookLessonController::class, 'store'])->name('lessons.store');
    Route::post('lessons/{lesson}/cancel', CancelLessonController::class)->name('lessons.cancel');

    Route::resource('learners', LearnerController::class);

    // R100: the fake driver's add-card page; both routes 404 outside the fake-gateway environments (R107).
    Route::get('payment-methods/create', [TestCardController::class, 'create'])->name('payment-methods.create');
    Route::post('payment-methods', [TestCardController::class, 'store'])->name('payment-methods.store');

    Route::get('weekly-slots/create', [WeeklySlotController::class, 'create'])->name('weekly-slots.create');
    Route::post('weekly-slots', [WeeklySlotController::class, 'store'])->name('weekly-slots.store');
    Route::get('weekly-slots/{slot}/end', [WeeklySlotController::class, 'endShow'])->whereNumber('slot')->name('weekly-slots.end.show');
    Route::post('weekly-slots/{slot}/end', [WeeklySlotController::class, 'end'])->whereNumber('slot')->name('weekly-slots.end');
    Route::post('weekly-slots/{slot}/resume', [WeeklySlotController::class, 'resume'])->whereNumber('slot')->name('weekly-slots.resume');

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

    Route::get('tutor/weekly-slots/{slot}/end', [TutorWeeklySlotController::class, 'endShow'])->whereNumber('slot')->name('tutor.weekly-slots.end.show');
    Route::post('tutor/weekly-slots/{slot}/end', [TutorWeeklySlotController::class, 'end'])->whereNumber('slot')->name('tutor.weekly-slots.end');

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
