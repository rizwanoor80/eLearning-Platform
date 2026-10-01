<?php

use App\Http\Controllers\Auth\TutorRegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Learner\LearnerController;
use App\Http\Controllers\Lessons\BookLessonController;
use App\Http\Controllers\Lessons\CancelLessonController;
use App\Http\Controllers\Lessons\DisputeController;
use App\Http\Controllers\Lessons\LessonRoomController;
use App\Http\Controllers\Lessons\ProgressReportController;
use App\Http\Controllers\Match\MatchRequestController;
use App\Http\Controllers\Messaging\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\Payments\TestCardController;
use App\Http\Controllers\Payments\WeeklySlotController;
use App\Http\Controllers\Reviews\ReviewController;
use App\Http\Controllers\Safeguarding\AbuseReportController;
use App\Http\Controllers\Tutor\TutorDashboardController;
use App\Http\Controllers\Tutor\TutorDocumentController;
use App\Http\Controllers\Tutor\TutorOnboardingController;
use App\Http\Controllers\Tutor\TutorProfileController;
use App\Http\Controllers\Tutor\TutorSearchController;
use App\Http\Controllers\Tutor\TutorWeeklySlotController;
use App\Http\Controllers\UnreadCountsController;
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

// CP7 8d (R137): filing a report against a tutor's profile. Named `abuse-reports.*`, never
// `tutors.*`: TutorProfilePolicy::reportAbuse is the authorisation, a non-party gets a 404.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('tutors/{tutor}/abuse-reports', [AbuseReportController::class, 'storeForTutor'])
        ->where('tutor', '[0-9]{1,18}')
        ->middleware('throttle:abuse-reports')
        ->name('abuse-reports.tutor.store');
});

// CP6 7c/7d: the lesson page and room, for the lesson's own tutor or parent. LessonPolicy::attend is the authorisation.
Route::middleware(['auth', 'verified'])->prefix('lessons/{lesson}')->where(['lesson' => '[0-9]{1,18}'])->group(function () {
    Route::get('/', [LessonRoomController::class, 'show'])->name('lessons.show');
    Route::post('room', [LessonRoomController::class, 'join'])->middleware('throttle:lesson-room')->name('lessons.room');
    Route::post('joined', [LessonRoomController::class, 'joined'])->name('lessons.joined');
    Route::post('no-show', [LessonRoomController::class, 'noShow'])->name('lessons.no-show');
    // CP6 7e: the tutor's report. LessonPolicy::report is the authorisation.
    Route::get('report', [ProgressReportController::class, 'create'])->name('lessons.report.create');
    Route::post('report', [ProgressReportController::class, 'store'])->name('lessons.report.store');

    // CP7 8d (R137): filing a safeguarding report against this lesson. Named `abuse-reports.*`,
    // deliberately not `lessons.report.*` (that name already means the tutor's progress report).
    // LessonPolicy::reportAbuse is the authorisation.
    Route::post('abuse-reports', [AbuseReportController::class, 'storeForLesson'])
        ->middleware('throttle:abuse-reports')
        ->name('abuse-reports.lesson.store');

    // CP7 8c (R136): the account holder's review of a completed lesson. LessonPolicy::review is the authorisation.
    Route::middleware('feature:reviews')->group(function () {
        Route::get('review', [ReviewController::class, 'create'])->name('lessons.review.create');
        Route::post('review', [ReviewController::class, 'store'])->middleware('throttle:reviews')->name('lessons.review.store');
    });

    // CP8 (R150): the account holder disputes a completed lesson, within 48h of ends_at.
    // LessonPolicy::openDispute is the authorisation.
    Route::get('dispute', [DisputeController::class, 'create'])->name('lessons.dispute.create');
    Route::post('dispute', [DisputeController::class, 'store'])->middleware('throttle:disputes')->name('lessons.dispute.store');
});

// CP7 8b (R133, R135): Messages for both portals — an account holder and a tutor each see their own
// conversations; ConversationPolicy answers anyone else with a 404. The badge counts are one JSON endpoint.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('unread-counts', UnreadCountsController::class)->middleware('throttle:unread-counts')->name('unread-counts');

    // CP7 8e (R139): the notification centre. Deliberately NOT added to
    // `EnsureAccountActive::EXCLUDED_ROUTE_PREFIXES` — unlike a closed conversation, which must stay
    // viewable so the parties can see why it closed, there is no requirement that a suspended user
    // keep reading or clearing their notifications, so the middleware's default logout-on-next-request
    // applies here same as everywhere else (cycle 08 DECISION).
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->whereUuid('notification')->name('notifications.read');
    Route::post('notifications/{notification}/open', [NotificationController::class, 'open'])->whereUuid('notification')->name('notifications.open');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

    Route::middleware('feature:messaging')->group(function () {
        Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
        Route::get('messages/{conversation}', [MessageController::class, 'show'])->whereNumber('conversation')->name('messages.show');
        Route::post('messages/{conversation}', [MessageController::class, 'store'])->whereNumber('conversation')->middleware('throttle:messages')->name('messages.store');

        // CP7 8d (R137): filing a report against this conversation. Named `abuse-reports.*`, NOT
        // `messages.*` — `EnsureAccountActive` exempts the `messages.` route-name prefix from its
        // suspended-user logout so a closed conversation stays viewable; naming this route under
        // that prefix would let an already-suspended user's surviving session keep filing reports.
        // ConversationPolicy::reportAbuse is the authorisation.
        Route::post('messages/{conversation}/abuse-reports', [AbuseReportController::class, 'storeForConversation'])
            ->whereNumber('conversation')
            ->middleware('throttle:abuse-reports')
            ->name('abuse-reports.conversation.store');
    });
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
    Route::post('tutor/onboarding/linkedin', [TutorOnboardingController::class, 'storeLinkedin'])->name('tutor.onboarding.linkedin');
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
