<?php

use App\Actions\Lessons\SettleSuspendedLesson;
use App\Enums\LessonStatus;
use App\Enums\TutorProfileStatus;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Tutors\TutorEarnings;
use Carbon\CarbonInterface;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * R250 (23g): the five earnings buckets, computed from lessons and the ledger. One test per bucket transition, and the
 * five always add up to the tutor's lifetime earnings worked out straight from the ledger.
 */
function ernTutor(): TutorProfile
{
    return TutorProfile::factory()->approved()->create(['user_id' => User::factory()->tutor()]);
}

/** A lesson of AED 100.00 (tutor share AED 75.00, commission AED 25.00) in the given status; held in escrow if `$held`. */
function ernLesson(TutorProfile $tutor, LessonStatus $status = LessonStatus::Confirmed, bool $held = true, ?CarbonInterface $endedAt = null): Lesson
{
    static $slot = 0;

    // A tutor cannot have two lessons at one start time, so each lesson gets its own hour. By default a lesson ended
    // days ago, so its dispute window (R258) has closed; `$endedAt` places the end exactly.
    $start = $endedAt?->copy()->subHour() ?? now()->subDays(60)->startOfHour()->addHours($slot++);
    $lesson = Lesson::factory()->withStatus($status)->startingAt($start)->create(['tutor_profile_id' => $tutor->id]);

    if ($held) {
        app(LedgerService::class)->hold($lesson);
    }

    return $lesson;
}

function ernSetStatus(Lesson $lesson, LessonStatus $status): void
{
    Lesson::allowingStatusWrites(fn () => $lesson->forceFill(['status' => $status])->save());
}

/** @return array{pending: int, on_hold: int, available: int, processing: int, paid: int} */
function ernBuckets(TutorProfile $tutor): array
{
    $b = app(TutorEarnings::class)->buckets($tutor);

    return [
        'pending' => $b->pending->toFils(),
        'on_hold' => $b->onHold->toFils(),
        'available' => $b->available->toFils(),
        'processing' => $b->processing->toFils(),
        'paid' => $b->paidToDate->toFils(),
    ];
}

function ernAssertSumsToLifetime(TutorProfile $tutor): void
{
    $buckets = app(TutorEarnings::class)->buckets($tutor);

    expect($buckets->total()->toFils())->toBe(app(TutorEarnings::class)->lifetime($tutor)->toFils());
}

it('is all zero for a tutor with no lessons', function () {
    $tutor = ernTutor();

    expect(ernBuckets($tutor))->toBe(['pending' => 0, 'on_hold' => 0, 'available' => 0, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($tutor);
});

it('puts a held confirmed, in-progress or completed lesson in pending, at the tutor share', function () {
    $tutor = ernTutor();
    ernLesson($tutor, LessonStatus::Confirmed);
    ernLesson($tutor, LessonStatus::InProgress);
    ernLesson($tutor, LessonStatus::Completed);

    expect(ernBuckets($tutor))->toBe(['pending' => 22500, 'on_hold' => 0, 'available' => 0, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($tutor);
});

it('does not count a lesson that has no payment held yet', function () {
    $tutor = ernTutor();
    ernLesson($tutor, LessonStatus::Confirmed, held: false);
    ernLesson($tutor, LessonStatus::PendingPayment, held: false);

    expect(ernBuckets($tutor)['pending'])->toBe(0);
    ernAssertSumsToLifetime($tutor);
});

it('moves a lesson from pending to available when it is released', function () {
    $tutor = ernTutor();
    $lesson = ernLesson($tutor, LessonStatus::Completed);

    app(LedgerService::class)->release($lesson);
    ernSetStatus($lesson, LessonStatus::CompletedReported);

    expect(ernBuckets($tutor))->toBe(['pending' => 0, 'on_hold' => 0, 'available' => 7500, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($tutor);
});

it('drops a lesson from pending when it is refunded', function () {
    $tutor = ernTutor();
    $lesson = ernLesson($tutor, LessonStatus::Confirmed);

    app(LedgerService::class)->refund($lesson);
    ernSetStatus($lesson, LessonStatus::Refunded);

    expect(ernBuckets($tutor))->toBe(['pending' => 0, 'on_hold' => 0, 'available' => 0, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($tutor);
});

it('moves a held lesson from pending to on hold when it is disputed', function () {
    $tutor = ernTutor();
    $lesson = ernLesson($tutor, LessonStatus::Completed);

    ernSetStatus($lesson, LessonStatus::Disputed);

    expect(ernBuckets($tutor))->toBe(['pending' => 0, 'on_hold' => 7500, 'available' => 0, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($tutor);
});

it('moves an already-released lesson from available to on hold when it is disputed, counted once', function () {
    $tutor = ernTutor();
    $lesson = ernLesson($tutor, LessonStatus::Completed);
    app(LedgerService::class)->release($lesson);
    ernSetStatus($lesson, LessonStatus::CompletedReported);

    expect(ernBuckets($tutor)['available'])->toBe(7500);

    ernSetStatus($lesson, LessonStatus::Disputed);

    expect(ernBuckets($tutor))->toBe(['pending' => 0, 'on_hold' => 7500, 'available' => 0, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($tutor);
});

it('moves a disputed lesson from on hold to available when the settlement pays the tutor in full', function () {
    $tutor = ernTutor();
    $lesson = ernLesson($tutor, LessonStatus::Completed);
    ernSetStatus($lesson, LessonStatus::Disputed);
    $dispute = Dispute::factory()->for($lesson)->create();

    app(LedgerService::class)->settle($lesson, $dispute, 0, 100);
    ernSetStatus($lesson, LessonStatus::Settled);

    expect(ernBuckets($tutor))->toBe(['pending' => 0, 'on_hold' => 0, 'available' => 7500, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($tutor);
});

it('takes a disputed lesson out of every bucket when the settlement refunds the parent and pays the tutor nothing', function () {
    $tutor = ernTutor();
    $lesson = ernLesson($tutor, LessonStatus::Completed);
    ernSetStatus($lesson, LessonStatus::Disputed);
    $dispute = Dispute::factory()->for($lesson)->create();

    app(LedgerService::class)->settle($lesson, $dispute, 100, 0);
    ernSetStatus($lesson, LessonStatus::Settled);

    expect(ernBuckets($tutor))->toBe(['pending' => 0, 'on_hold' => 0, 'available' => 0, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($tutor);
});

it('settles an already-released lesson to a part payment: the reversal leaves the tutor with the part', function () {
    $tutor = ernTutor();
    $lesson = ernLesson($tutor, LessonStatus::Completed);
    app(LedgerService::class)->release($lesson);
    ernSetStatus($lesson, LessonStatus::Disputed);
    $dispute = Dispute::factory()->for($lesson)->create();

    app(LedgerService::class)->settle($lesson, $dispute, 50, 50);
    ernSetStatus($lesson, LessonStatus::Settled);

    expect(ernBuckets($tutor))->toBe(['pending' => 0, 'on_hold' => 0, 'available' => 3750, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($tutor);
});

it('keeps each tutor to their own lessons', function () {
    $mine = ernTutor();
    $other = ernTutor();
    ernLesson($mine, LessonStatus::Confirmed);
    $released = ernLesson($other, LessonStatus::Completed);
    app(LedgerService::class)->release($released);
    ernSetStatus($released, LessonStatus::CompletedReported);

    expect(ernBuckets($mine))->toBe(['pending' => 7500, 'on_hold' => 0, 'available' => 0, 'processing' => 0, 'paid' => 0])
        ->and(ernBuckets($other))->toBe(['pending' => 0, 'on_hold' => 0, 'available' => 7500, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($mine);
    ernAssertSumsToLifetime($other);
});

it('adds the five buckets up to the lifetime earnings in a mixed ledger', function () {
    $tutor = ernTutor();
    $ledger = app(LedgerService::class);

    ernLesson($tutor, LessonStatus::Confirmed);
    $released = ernLesson($tutor, LessonStatus::Completed);
    $ledger->release($released);
    ernSetStatus($released, LessonStatus::CompletedReported);
    $refunded = ernLesson($tutor, LessonStatus::Confirmed);
    $ledger->refund($refunded);
    ernSetStatus($refunded, LessonStatus::Refunded);
    $disputed = ernLesson($tutor, LessonStatus::Completed);
    ernSetStatus($disputed, LessonStatus::Disputed);
    $disputedReleased = ernLesson($tutor, LessonStatus::Completed);
    $ledger->release($disputedReleased);
    ernSetStatus($disputedReleased, LessonStatus::Disputed);
    $settled = ernLesson($tutor, LessonStatus::Completed);
    ernSetStatus($settled, LessonStatus::Disputed);
    $ledger->settle($settled, Dispute::factory()->for($settled)->create(), 25, 75);
    ernSetStatus($settled, LessonStatus::Settled);

    $buckets = ernBuckets($tutor);

    // Settled at 75% on a held AED 75.00 share: the tutor is paid 5625 fils.
    expect($buckets)->toBe(['pending' => 7500, 'on_hold' => 15000, 'available' => 7500 + 5625, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($tutor);
    expect($ledger->unbalancedLessons())->toBeEmpty();
});

it('pays out a settlement at 60 percent to the tutor: available is the 60 percent share', function () {
    $tutor = ernTutor();
    $lesson = ernLesson($tutor, LessonStatus::Completed);
    ernSetStatus($lesson, LessonStatus::Disputed);

    app(LedgerService::class)->settle($lesson, Dispute::factory()->for($lesson)->create(), 40, 60);
    ernSetStatus($lesson, LessonStatus::Settled);

    expect(ernBuckets($tutor))->toBe(['pending' => 0, 'on_hold' => 0, 'available' => 4500, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($tutor);
});

it('moves a late parent cancellation to available, and an early one to nothing', function () {
    // A late cancel ends completed_reported (released to the tutor); an early one stays cancelled_by_parent (refunded).
    $tutor = ernTutor();
    $late = ernLesson($tutor, LessonStatus::Confirmed);
    $early = ernLesson($tutor, LessonStatus::Confirmed);

    app(LedgerService::class)->release($late);
    ernSetStatus($late, LessonStatus::CompletedReported);
    app(LedgerService::class)->refund($early);
    ernSetStatus($early, LessonStatus::CancelledByParent);

    expect(ernBuckets($tutor))->toBe(['pending' => 0, 'on_hold' => 0, 'available' => 7500, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($tutor);
});

it('moves a student no-show to available and a tutor no-show to nothing', function () {
    $tutor = ernTutor();
    $studentNoShow = ernLesson($tutor, LessonStatus::Confirmed);
    $tutorNoShow = ernLesson($tutor, LessonStatus::Confirmed);

    app(LedgerService::class)->release($studentNoShow);
    ernSetStatus($studentNoShow, LessonStatus::NoShowStudent);
    app(LedgerService::class)->refund($tutorNoShow);
    ernSetStatus($tutorNoShow, LessonStatus::NoShowTutor);

    expect(ernBuckets($tutor))->toBe(['pending' => 0, 'on_hold' => 0, 'available' => 7500, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($tutor);
});

// ---- the page -------------------------------------------------------------------------------

it('shows a tutor the five buckets, formatted, with no payout history yet', function () {
    $tutor = ernTutor();
    $lesson = ernLesson($tutor, LessonStatus::Completed);
    app(LedgerService::class)->release($lesson);
    ernSetStatus($lesson, LessonStatus::CompletedReported);
    ernLesson($tutor, LessonStatus::Confirmed);

    $this->actingAs($tutor->user)->get('/tutor/earnings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tutor/Earnings')
            ->where('buckets.pending', 'AED 75.00')
            ->where('buckets.on_hold', 'AED 0.00')
            ->where('buckets.available', 'AED 75.00')
            ->where('buckets.processing', 'AED 0.00')
            ->where('buckets.paid_to_date', 'AED 0.00')
            ->where('buckets.total', 'AED 150.00')
            ->where('payouts', [])
            ->where('payout_problem', 'No payout method chosen.'));
});

it('shows a tutor with no profile row an empty page', function () {
    $user = User::factory()->tutor()->create();

    $this->actingAs($user)->get('/tutor/earnings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tutor/Earnings')->where('buckets', null));
});

it('does not show the page to a parent or a guest', function () {
    $this->get('/tutor/earnings')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/tutor/earnings')->assertForbidden();
});

it('never reads another tutor lessons into the page', function () {
    $mine = ernTutor();
    ernLesson(ernTutor(), LessonStatus::Confirmed);

    $this->actingAs($mine->user)->get('/tutor/earnings')
        ->assertInertia(fn (Assert $page) => $page->where('buckets.pending', 'AED 0.00'));
});

it('still shows the page to a suspended tutor', function () {
    $tutor = ernTutor();
    $tutor->forceFill(['status' => TutorProfileStatus::Suspended])->save();
    $lesson = ernLesson($tutor, LessonStatus::Completed);
    app(LedgerService::class)->release($lesson);
    ernSetStatus($lesson, LessonStatus::CompletedReported);

    $this->actingAs($tutor->user)->get('/tutor/earnings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('buckets.available', 'AED 75.00'));
});

// --- R258: released money waits for the dispute window -----------------------------------------------------------

it('keeps released money in pending, with its date, until 48 hours after the lesson ended, then moves it to available', function () {
    $this->freezeTime();
    $tutor = ernTutor();
    $ended = now()->subHours(47);
    $lesson = ernLesson($tutor, LessonStatus::Completed, endedAt: $ended);
    app(LedgerService::class)->release($lesson);
    ernSetStatus($lesson, LessonStatus::CompletedReported);

    $buckets = app(TutorEarnings::class)->buckets($tutor);

    expect(ernBuckets($tutor))->toBe(['pending' => 7500, 'on_hold' => 0, 'available' => 0, 'processing' => 0, 'paid' => 0])
        ->and($buckets->pendingAvailableAt?->toIso8601String())->toBe($ended->copy()->addHours(48)->utc()->toIso8601String());
    ernAssertSumsToLifetime($tutor);

    $this->travel(2)->hours();

    expect(ernBuckets($tutor))->toBe(['pending' => 0, 'on_hold' => 0, 'available' => 7500, 'processing' => 0, 'paid' => 0])
        ->and(app(TutorEarnings::class)->buckets($tutor)->pendingAvailableAt)->toBeNull();
    ernAssertSumsToLifetime($tutor);
});

it('waits seven days for a lesson that was settled after a suspension', function () {
    $this->freezeTime();
    $tutor = ernTutor();
    $lesson = ernLesson($tutor, LessonStatus::Completed, endedAt: now()->subHours(49));
    app(LedgerService::class)->release($lesson);
    ernSetStatus($lesson, LessonStatus::CompletedReported);
    AuditLog::query()->create([
        'actor_user_id' => null,
        'action' => SettleSuspendedLesson::AUDIT_SETTLED,
        'subject_type' => Lesson::class,
        'subject_id' => $lesson->id,
    ]);

    expect(ernBuckets($tutor)['pending'])->toBe(7500)->and(ernBuckets($tutor)['available'])->toBe(0);

    // It ended 49 hours ago, so the seven days run out 119 hours from now.
    $this->travel(118)->hours();
    expect(ernBuckets($tutor)['available'])->toBe(0);

    $this->travel(2)->hours();
    expect(ernBuckets($tutor))->toBe(['pending' => 0, 'on_hold' => 0, 'available' => 7500, 'processing' => 0, 'paid' => 0]);
    ernAssertSumsToLifetime($tutor);
});

it('does not delay money on a lesson that can no longer be disputed', function () {
    $this->freezeTime();
    $tutor = ernTutor();
    $lesson = ernLesson($tutor, LessonStatus::Completed, endedAt: now()->subHour());
    app(LedgerService::class)->release($lesson);
    ernSetStatus($lesson, LessonStatus::Settled);

    expect(ernBuckets($tutor)['available'])->toBe(7500)->and(ernBuckets($tutor)['pending'])->toBe(0);
});

it('shows a tutor the date released money becomes available', function () {
    $this->freezeTime();
    $tutor = ernTutor();
    $lesson = ernLesson($tutor, LessonStatus::Completed, endedAt: now()->subHours(1));
    app(LedgerService::class)->release($lesson);
    ernSetStatus($lesson, LessonStatus::CompletedReported);

    $this->actingAs($tutor->user)->get('/tutor/earnings')->assertInertia(fn (Assert $page) => $page
        ->where('buckets.pending_available_on', now()->addHours(47)->setTimezone($tutor->user->timezone)->format('j M Y')));
});
