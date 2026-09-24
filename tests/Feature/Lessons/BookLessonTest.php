<?php

use App\Actions\Lessons\BookLesson;
use App\Enums\CurriculumCode;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\LevelTier;
use App\Enums\PaymentStatus;
use App\Exceptions\BookingException;
use App\Exceptions\PaymentCaptureException;
use App\Models\AvailabilityRule;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PriceBand;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Services\Lessons\LessonStateMachine;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentCaptureResult;
use App\Services\Payments\PaymentGateway;
use App\Services\Scheduling\SlotCalculator;
use App\Support\Facades\Settings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// 2026-09-14 06:00 UTC is a Monday; Tuesday is weekday 2 (Sunday = 0) — same
// reference point as SlotCalculatorLoaderTest/PermitCapTest.
const BOOK_LESSON_NOW = '2026-09-14 06:00:00';

function bookLessonNow(): CarbonImmutable
{
    return CarbonImmutable::parse(BOOK_LESSON_NOW, 'UTC');
}

/**
 * A tutor that is bookable end to end: approved, permit valid, a rate inside
 * its own band, one taught subject, and a weekly availability rule producing
 * slots on the Tuesday after BOOK_LESSON_NOW.
 *
 * @return array{tutor: TutorProfile, curriculum_id: int, subject_id: int}
 */
function bookableTutorSetup(array $tutorOverrides = []): array
{
    $curriculum = Curriculum::query()->firstOrCreate(
        ['code' => CurriculumCode::Gcse],
        ['name' => CurriculumCode::Gcse->value, 'sort' => 0],
    );
    $subject = Subject::factory()->create();

    $tutor = TutorProfile::factory()->approved()->create(array_merge(['hourly_rate' => 10000], $tutorOverrides));

    TutorSubject::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'curriculum_id' => $curriculum->id,
        'subject_id' => $subject->id,
        'level_tier' => LevelTier::LowerSecondary,
    ]);

    PriceBand::query()->firstOrCreate(
        ['curriculum_id' => $curriculum->id, 'level_tier' => LevelTier::LowerSecondary, 'effective_from' => '2000-01-01'],
        ['min_rate' => 5000, 'max_rate' => 20000],
    );

    AvailabilityRule::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'weekday' => 2,
        'start_time' => '09:00:00',
        'end_time' => '12:00:00',
        'timezone' => 'UTC',
    ]);

    return ['tutor' => $tutor->fresh(), 'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id];
}

function bindFakeGateway(): void
{
    app()->instance(PaymentGateway::class, new FakePaymentGateway);
}

/**
 * @return array{parent: User, learner: Learner}
 */
function parentAndLearner(int $curriculumId): array
{
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => $curriculumId]);

    return ['parent' => $parent, 'learner' => $learner];
}

beforeEach(function () {
    test()->travelTo(bookLessonNow());
    bindFakeGateway();
});

it('books the first lesson for a pair as a discounted trial and the second as full-price regular', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup();
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);

    $book = app(BookLesson::class);
    $data = ['curriculum_id' => $curriculumId, 'subject_id' => $subjectId];

    $trial = $book($parent, $learner, $tutor, [...$data, 'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC')]);

    expect($trial->type)->toBe(LessonType::Trial)
        ->and($trial->status)->toBe(LessonStatus::Confirmed)
        ->and($trial->price->toFils())->toBe(5000);

    $regular = $book($parent, $learner, $tutor, [...$data, 'starts_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC')]);

    expect($regular->type)->toBe(LessonType::Regular)
        ->and($regular->price->toFils())->toBe(10000);
});

it('does not let a cancelled trial consume the pair\'s one trial', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup();
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);

    $book = app(BookLesson::class);
    $data = ['curriculum_id' => $curriculumId, 'subject_id' => $subjectId];

    $trial = $book($parent, $learner, $tutor, [...$data, 'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC')]);
    LessonStateMachine::transition($trial, LessonStatus::CancelledByTutor);

    $secondTrial = $book($parent, $learner, $tutor, [...$data, 'starts_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC')]);

    expect($secondTrial->type)->toBe(LessonType::Trial)
        ->and($secondTrial->price->toFils())->toBe(5000);
});

it('truncates the tutor share and gives the platform the odd fil on the commission split', function () {
    Settings::set('commission_pct', 25);

    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup(['hourly_rate' => 10001]);
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);

    $book = app(BookLesson::class);
    $data = ['curriculum_id' => $curriculumId, 'subject_id' => $subjectId];

    // First booking consumes the trial; the second is regular, at the odd full rate.
    $book($parent, $learner, $tutor, [...$data, 'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC')]);
    $regular = $book($parent, $learner, $tutor, [...$data, 'starts_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC')]);

    expect($regular->price->toFils())->toBe(10001)
        ->and($regular->tutor_amount->toFils())->toBe(7500)
        ->and($regular->commission_amount->toFils())->toBe(2501);
});

it('books at the trial-discounted price even when that price falls below the tutor\'s rate band minimum', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup(['hourly_rate' => 5000]);
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);

    $book = app(BookLesson::class);

    $trial = $book($parent, $learner, $tutor, [
        'curriculum_id' => $curriculumId,
        'subject_id' => $subjectId,
        'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'),
    ]);

    // Band check runs against the stored hourly_rate (5000, at the band minimum), never the
    // discounted trial price (2500, below it) — TutorProfile::trialPrice()'s docblock.
    expect($trial->price->toFils())->toBe(2500);
});

/**
 * R53 ("a test proving preview and booked price agree across the whole band
 * table including an odd-fils case"): TutorOnboardingBankSubjectsRateTest.php
 * already proves the onboarding preview prop equals TutorProfile::trialPrice()
 * for these same three rates, but never books a lesson — so it cannot prove
 * "booked price" agrees with anything. This test books an actual trial for
 * each rate and asserts the lesson's frozen price equals trialPrice() read
 * fresh from the database, closing that gap. Same three cases as the
 * onboarding dataset: band minimum, band maximum, and the docblock's own
 * odd-fils worked example (10001 @ 50% -> 5000, not the old formula's 5001).
 */
it('books a trial at exactly TutorProfile::trialPrice(), agreeing across the band table including an odd-fils case', function (int $hourlyRateFils, int $discountPct, int $expectedTrialFils) {
    Settings::set('trial_discount_pct', $discountPct);

    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup(['hourly_rate' => $hourlyRateFils]);
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);

    expect($tutor->trialPrice()->toFils())->toBe($expectedTrialFils);

    $trial = app(BookLesson::class)($parent, $learner, $tutor, [
        'curriculum_id' => $curriculumId,
        'subject_id' => $subjectId,
        'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'),
    ]);

    expect($trial->type)->toBe(LessonType::Trial)
        ->and($trial->price->toFils())->toBe($expectedTrialFils)
        ->and($trial->price->toFils())->toBe($tutor->fresh()->trialPrice()->toFils());
})->with([
    // bookableTutorSetup()'s own PriceBand is min_rate 5000, max_rate 20000.
    'band minimum, even split (5000 @ 25% -> 3750)' => [5000, 25, 3750],
    'band maximum, even split (20000 @ 25% -> 15000)' => [20000, 25, 15000],
    'the docblock\'s own odd-fils example (10001 @ 50% -> 5000, not 5001)' => [10001, 50, 5000],
]);

it('refuses to book when the computed price rounds to zero, writing no lesson and no payment row (R77)', function () {
    // A 1-fil hourly rate at the 99%-capped max discount (item 1 makes 100%/free unreachable
    // through the settings form, but this combination still rounds the trial price to 0 fils via
    // TutorProfile::trialPrice()'s own rounding rule — exactly the case the guard must catch).
    Settings::set('trial_discount_pct', 99);

    $curriculum = Curriculum::query()->firstOrCreate(
        ['code' => CurriculumCode::Gcse],
        ['name' => CurriculumCode::Gcse->value, 'sort' => 0],
    );
    $subject = Subject::factory()->create();
    $tutor = TutorProfile::factory()->approved()->create(['hourly_rate' => 1]);

    TutorSubject::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'curriculum_id' => $curriculum->id,
        'subject_id' => $subject->id,
        'level_tier' => LevelTier::LowerSecondary,
    ]);

    PriceBand::query()->firstOrCreate(
        ['curriculum_id' => $curriculum->id, 'level_tier' => LevelTier::LowerSecondary, 'effective_from' => '2000-01-01'],
        ['min_rate' => 1, 'max_rate' => 100],
    );

    AvailabilityRule::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'weekday' => 2,
        'start_time' => '09:00:00',
        'end_time' => '12:00:00',
        'timezone' => 'UTC',
    ]);

    $tutor = $tutor->fresh();
    expect($tutor->trialPrice()->toFils())->toBe(0);

    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculum->id);

    expect(fn () => app(BookLesson::class)($parent, $learner, $tutor, [
        'curriculum_id' => $curriculum->id,
        'subject_id' => $subject->id,
        'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'),
    ]))->toThrow(BookingException::class);

    expect(Lesson::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
});

it('stores a Dubai-timezone booking request as UTC on the raw row', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup();
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);

    $book = app(BookLesson::class);

    // 2026-09-15 09:00 UTC expressed in Asia/Dubai (UTC+4) is 13:00.
    $lesson = $book($parent, $learner, $tutor, [
        'curriculum_id' => $curriculumId,
        'subject_id' => $subjectId,
        'starts_at' => CarbonImmutable::parse('2026-09-15 13:00:00', 'Asia/Dubai'),
    ]);

    $raw = DB::table('lessons')->where('id', $lesson->id)->first();

    expect($raw->starts_at)->toBe('2026-09-15 09:00:00');
});

it('freezes price and policy on the lesson at booking time, unaffected by later settings and rate changes', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup();
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);

    // Distinct, non-default values so a hard-coded or swapped value in BookLesson would be
    // caught, not just a value that happens to survive untouched (config/settings.php defaults:
    // commission 25, cancel window 24h, student grace 15m, tutor grace 10m).
    Settings::set('commission_pct', 30);
    Settings::set('cancel_window_hours', 12);
    Settings::set('student_grace_min', 7);
    Settings::set('tutor_grace_min', 9);

    // Consume the trial first so this booking is a full-price regular lesson.
    $book = app(BookLesson::class);
    $data = ['curriculum_id' => $curriculumId, 'subject_id' => $subjectId];
    $book($parent, $learner, $tutor, [...$data, 'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC')]);
    $first = $book($parent, $learner, $tutor, [...$data, 'starts_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC')]);

    expect($first->price->toFils())->toBe(10000)
        ->and($first->commission_pct)->toBe(30)
        ->and($first->commission_amount->toFils())->toBe(3000)
        ->and($first->tutor_amount->toFils())->toBe(7000)
        ->and($first->cancel_window_hours)->toBe(12)
        ->and($first->student_grace_min)->toBe(7)
        ->and($first->tutor_grace_min)->toBe(9);

    $hold = LedgerEntry::query()->where('lesson_id', $first->id)->get();
    expect($hold->sum('amount'))->toBe(0)
        ->and($hold->firstWhere('account', LedgerAccount::Escrow)->amount)->toBe(10000);

    // Change everything the freeze covers, then book a second lesson for the same pair.
    Settings::set('commission_pct', 40);
    Settings::set('cancel_window_hours', 6);
    Settings::set('student_grace_min', 5);
    Settings::set('tutor_grace_min', 5);
    $tutor->forceFill(['hourly_rate' => 15000])->save();
    PriceBand::query()->create([
        'curriculum_id' => $curriculumId, 'level_tier' => LevelTier::LowerSecondary,
        'effective_from' => now()->toDateString(), 'min_rate' => 12000, 'max_rate' => 20000,
    ]);

    $second = $book($parent, $learner, $tutor, [...$data, 'starts_at' => CarbonImmutable::parse('2026-09-15 11:00:00', 'UTC')]);

    // The second lesson reads the new values...
    expect($second->price->toFils())->toBe(15000)
        ->and($second->commission_pct)->toBe(40)
        ->and($second->cancel_window_hours)->toBe(6)
        ->and($second->student_grace_min)->toBe(5)
        ->and($second->tutor_grace_min)->toBe(5);

    // ...while the first, already-booked lesson is untouched by any of it (invariant #6/#11).
    $reloaded = Lesson::query()->findOrFail($first->id);
    expect($reloaded->price->toFils())->toBe(10000)
        ->and($reloaded->commission_pct)->toBe(30)
        ->and($reloaded->commission_amount->toFils())->toBe(3000)
        ->and($reloaded->tutor_amount->toFils())->toBe(7000)
        ->and($reloaded->cancel_window_hours)->toBe(12)
        ->and($reloaded->student_grace_min)->toBe(7)
        ->and($reloaded->tutor_grace_min)->toBe(9);
});

it('expires the lesson and records a failed payment when capture is declined, writing no ledger entries', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup();
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);

    app()->instance(PaymentGateway::class, new class implements PaymentGateway
    {
        public function driver(): string
        {
            return 'fake';
        }

        public function capture(Lesson $lesson, Money $amount, string $idempotencyKey): PaymentCaptureResult
        {
            throw new PaymentCaptureException('card_declined');
        }
    });

    $book = app(BookLesson::class);

    expect(fn () => $book($parent, $learner, $tutor, [
        'curriculum_id' => $curriculumId,
        'subject_id' => $subjectId,
        'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'),
    ]))->toThrow(PaymentCaptureException::class);

    $lesson = Lesson::query()->latest('id')->firstOrFail();
    expect($lesson->status)->toBe(LessonStatus::Expired);

    $payment = Payment::query()->where('lesson_id', $lesson->id)->firstOrFail();
    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('card_declined')
        ->and($payment->gateway_ref)->toBeNull();

    expect(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe(0);
});

it('records a captured payment but no HOLD when the sweep expires the lesson before confirmation', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup();
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);

    app()->instance(PaymentGateway::class, new class implements PaymentGateway
    {
        public function driver(): string
        {
            return 'fake';
        }

        public function capture(Lesson $lesson, Money $amount, string $idempotencyKey): PaymentCaptureResult
        {
            // Simulates the 15-minute unpaid sweep winning the race right after capture.
            LessonStateMachine::transition($lesson, LessonStatus::Expired);

            return new PaymentCaptureResult(gatewayRef: 'race_ref');
        }
    });

    $book = app(BookLesson::class);

    expect(fn () => $book($parent, $learner, $tutor, [
        'curriculum_id' => $curriculumId,
        'subject_id' => $subjectId,
        'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'),
    ]))->toThrow(BookingException::class);

    $lesson = Lesson::query()->latest('id')->firstOrFail();
    expect($lesson->status)->toBe(LessonStatus::Expired);

    $payment = Payment::query()->where('lesson_id', $lesson->id)->firstOrFail();
    expect($payment->status)->toBe(PaymentStatus::Captured);

    expect(LedgerEntry::query()->where('lesson_id', $lesson->id)->count())->toBe(0);

    // The stranded payment above isn't just documented in-memory — `ledger:verify`
    // (invariant #1, R77 item 3) actually catches it once it's past the grace
    // window, so the sweep can never leave one of these silently unflagged forever.
    $this->travel(6)->minutes();

    $this->artisan('ledger:verify')
        ->expectsOutputToContain("Payment {$payment->id} on lesson {$lesson->id} is captured with no matching ledger hold (invariant #1).")
        ->assertExitCode(1);
});

it('leaves ledger:verify clean after a normal successful booking, even past the stranded-payment grace window', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup();
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);

    $book = app(BookLesson::class);

    $lesson = $book($parent, $learner, $tutor, [
        'curriculum_id' => $curriculumId,
        'subject_id' => $subjectId,
        'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'),
    ]);

    expect($lesson->status)->toBe(LessonStatus::Confirmed);

    $this->travel(6)->minutes();

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('Ledger OK: every lesson sums to zero.')
        ->assertExitCode(0);
});

it('lets the same slot and trial be rebooked after a declined capture expires the lesson', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup();
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);
    $slot = ['curriculum_id' => $curriculumId, 'subject_id' => $subjectId, 'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC')];

    app()->instance(PaymentGateway::class, new class implements PaymentGateway
    {
        public function driver(): string
        {
            return 'fake';
        }

        public function capture(Lesson $lesson, Money $amount, string $idempotencyKey): PaymentCaptureResult
        {
            throw new PaymentCaptureException('card_declined');
        }
    });

    expect(fn () => app(BookLesson::class)($parent, $learner, $tutor, $slot))->toThrow(PaymentCaptureException::class);
    expect(Lesson::query()->sole()->status)->toBe(LessonStatus::Expired);

    // A fresh capture attempt on the same slot and pair: the `expired` lesson must not hold the
    // slot (lessons_tutor_slot_unique / lessons_tutor_no_overlap) or the trial
    // (lessons_one_trial_per_pair) — all three predicates share LessonStatus::freeingSlotValues().
    bindFakeGateway();
    $rebooked = app(BookLesson::class)($parent, $learner, $tutor, $slot);

    expect($rebooked->status)->toBe(LessonStatus::Confirmed)
        ->and($rebooked->type)->toBe(LessonType::Trial);
});

it('lets the same slot be rebooked after the tutor cancels the confirmed lesson', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup();
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);
    $slot = ['curriculum_id' => $curriculumId, 'subject_id' => $subjectId, 'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC')];

    $first = app(BookLesson::class)($parent, $learner, $tutor, $slot);
    expect($first->status)->toBe(LessonStatus::Confirmed);

    LessonStateMachine::transition($first, LessonStatus::CancelledByTutor);

    $rebooked = app(BookLesson::class)($parent, $learner, $tutor, $slot);

    expect($rebooked->status)->toBe(LessonStatus::Confirmed)
        ->and($rebooked->type)->toBe(LessonType::Trial);
});

it('refuses to book a learner that is not the caller\'s own', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup();
    $owner = User::factory()->create();
    $someoneElse = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $owner->id, 'curriculum_id' => $curriculumId]);

    $book = app(BookLesson::class);

    expect(fn () => $book($someoneElse, $learner, $tutor, [
        'curriculum_id' => $curriculumId,
        'subject_id' => $subjectId,
        'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'),
    ]))->toThrow(BookingException::class);

    expect(Lesson::query()->count())->toBe(0);
});

it('refuses to book a tutor that is not bookable', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup();
    $tutor->forceFill(['permit_expires_at' => now()->subDay()])->save();
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);

    $book = app(BookLesson::class);

    expect(fn () => $book($parent, $learner, $tutor, [
        'curriculum_id' => $curriculumId,
        'subject_id' => $subjectId,
        'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'),
    ]))->toThrow(BookingException::class);
});

it('refuses to book a subject the tutor does not teach', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId] = bookableTutorSetup();
    $otherSubject = Subject::factory()->create();
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);

    $book = app(BookLesson::class);

    expect(fn () => $book($parent, $learner, $tutor, [
        'curriculum_id' => $curriculumId,
        'subject_id' => $otherSubject->id,
        'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'),
    ]))->toThrow(BookingException::class);
});

it('refuses a second learner racing the same tutor slot at the database, not just the app-level check, leaving exactly one confirmed lesson and one HOLD behind (R74 in-process concurrency proof)', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup();
    ['parent' => $firstParent, 'learner' => $firstLearner] = parentAndLearner($curriculumId);
    ['parent' => $secondParent, 'learner' => $secondLearner] = parentAndLearner($curriculumId);
    $slot = ['curriculum_id' => $curriculumId, 'subject_id' => $subjectId, 'starts_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC')];

    // Both learners' requests are served the same pre-race availability, exactly what two
    // concurrent readers would each have seen before either write lands — the "slot no longer
    // available" app-level check (BookLesson.php:80) never fires, so the second call reaches
    // LessonStateMachine::open() and the real database constraint decides it.
    $staleSlots = app(SlotCalculator::class)->forTutor($tutor, 'UTC');
    app()->instance(SlotCalculator::class, new class($staleSlots) extends SlotCalculator
    {
        public function __construct(private readonly array $staleSlots) {}

        public function forTutor(TutorProfile $tutor, string $timezone, ?CarbonInterface $now = null, ?int $withinDays = null): array
        {
            return $this->staleSlots;
        }
    });

    $book = app(BookLesson::class);
    $winner = $book($firstParent, $firstLearner, $tutor, $slot);
    expect($winner->status)->toBe(LessonStatus::Confirmed);

    $caught = null;
    try {
        $book($secondParent, $secondLearner, $tutor, $slot);
    } catch (BookingException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getPrevious())->toBeInstanceOf(QueryException::class)
        ->and($caught->getPrevious()->getMessage())->toMatch('/lessons_tutor_slot_unique|lessons_tutor_no_overlap/');

    expect(Lesson::query()->where('tutor_profile_id', $tutor->id)->count())->toBe(1)
        ->and(Lesson::query()->findOrFail($winner->id)->status)->toBe(LessonStatus::Confirmed)
        ->and(Payment::query()->count())->toBe(1);

    $hold = LedgerEntry::query()->where('lesson_id', $winner->id)->get();
    expect($hold->sum('amount'))->toBe(0)
        ->and($hold->firstWhere('account', LedgerAccount::Escrow)->amount)->toBe($winner->price->toFils());
});

it('refuses to book a slot the tutor has no availability for', function () {
    ['tutor' => $tutor, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId] = bookableTutorSetup();
    ['parent' => $parent, 'learner' => $learner] = parentAndLearner($curriculumId);

    $book = app(BookLesson::class);

    expect(fn () => $book($parent, $learner, $tutor, [
        'curriculum_id' => $curriculumId,
        'subject_id' => $subjectId,
        // Outside the 09:00-12:00 Tuesday rule.
        'starts_at' => CarbonImmutable::parse('2026-09-15 20:00:00', 'UTC'),
    ]))->toThrow(BookingException::class);

    expect(Lesson::query()->count())->toBe(0);
});
