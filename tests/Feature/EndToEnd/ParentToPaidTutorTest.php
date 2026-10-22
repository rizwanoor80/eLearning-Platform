<?php

use App\Actions\Lessons\RecordAttendance;
use App\Actions\Payouts\GeneratePayoutBatch;
use App\Actions\Payouts\MarkPayoutLinePaid;
use App\Enums\CurriculumCode;
use App\Enums\LedgerAccount;
use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\LevelTier;
use App\Enums\PaymentStatus;
use App\Enums\PayoutLineStatus;
use App\Enums\RecurringSlotStatus;
use App\Enums\VideoParticipant;
use App\Models\AvailabilityRule;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PriceBand;
use App\Models\RecurringSlot;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Models\YearGroup;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use App\Support\Facades\Settings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * CP8 acceptance (25e, R270): the whole v1 money path as one test, on the fake gateway, through the real actions and
 * routes. A parent registers, adds a child, books the trial, the trial is taught and reported, the parent sets up a weekly
 * slot, the nightly jobs generate and charge its lesson, that lesson is taught and reported, an admin pays the tutor past
 * the dispute window, and the tutor's earnings page shows the money under "paid to date".
 *
 * "Now" starts on Monday 2026-09-14 06:00 UTC. The tutor is open on Tuesdays 09:00-12:00 UTC at AED 200.00 an hour, so the
 * trial is Tuesday 2026-09-15 09:00 and the weekly slot is Tuesdays at 10:00 from 2026-09-22. `ledger:verify` runs after every
 * step that can move money, and the money is checked against the amounts frozen on the lessons, not against literals.
 */
function e2eLedgerIsGreen(): void
{
    expect(Artisan::call('ledger:verify', ['--no-interaction' => true]))->toBe(0);
}

/** Run one scheduled command and fail loudly if it did not succeed. */
function e2eRun(string $command): void
{
    expect(Artisan::call($command, ['--no-interaction' => true]))->toBe(0);
}

/**
 * Both sides join one minute into the lesson; the lesson ends and the settle-ended job completes it.
 */
function e2eTeach(Lesson $lesson): Lesson
{
    $join = $lesson->starts_at->addMinutes(1);
    test()->travelTo($join);

    expect(app(RecordAttendance::class)($lesson->fresh(), VideoParticipant::Tutor, $join))->toBeTrue()
        ->and(app(RecordAttendance::class)($lesson->fresh(), VideoParticipant::Learner, $join))->toBeTrue()
        ->and($lesson->fresh()->status)->toBe(LessonStatus::InProgress);

    test()->travelTo($lesson->ends_at->addMinutes(1));
    e2eRun('lessons:settle-ended');

    $lesson = $lesson->fresh();
    expect($lesson->status)->toBe(LessonStatus::Completed)
        ->and($lesson->completed_at)->not->toBeNull()
        ->and($lesson->report_due_at)->not->toBeNull();
    e2eLedgerIsGreen();

    return $lesson;
}

/**
 * @return array<string, mixed>
 */
function e2eReport(bool $trial): array
{
    return [
        'topics_covered' => 'Fractions and ratios.',
        'went_well' => 'Confident with the worked examples.',
        'work_on_next' => 'Word problems.',
        'homework' => 'Exercise 4b.',
        'engagement' => 4,
        ...($trial ? [
            'trial_suitability' => 'good_fit',
            'trial_recommended_frequency' => 1,
            'trial_focus_areas' => 'Algebra fluency before the mock exam.',
        ] : []),
    ];
}

it('takes a parent from sign-up to a tutor paid, with the ledger at zero at every step', function () {
    Mail::fake();
    test()->travelTo(CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC'));
    app()->instance(PaymentGateway::class, new FakePaymentGateway);
    $ledger = app(LedgerService::class);
    $currency = (string) Settings::get('currency_code');

    // --- the tutor: approved, bookable, paid by bank transfer, open Tuesday mornings --------------------------------
    $curriculum = Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0]);
    $subject = Subject::factory()->create();
    $tutor = TutorProfile::factory()->approved()->create([
        'hourly_rate' => 20000,
        'country' => 'AE',
        'payout_method' => 'bank_transfer',
        'user_id' => User::factory()->tutor(),
    ]);
    TutorSubject::factory()->create([
        'tutor_profile_id' => $tutor->id, 'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'level_tier' => LevelTier::LowerSecondary,
    ]);
    PriceBand::query()->firstOrCreate(
        ['curriculum_id' => $curriculum->id, 'level_tier' => LevelTier::LowerSecondary, 'effective_from' => '2000-01-01'],
        ['min_rate' => 5000, 'max_rate' => 20000],
    );
    AvailabilityRule::factory()->create([
        'tutor_profile_id' => $tutor->id, 'weekday' => 2, 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'timezone' => 'UTC',
    ]);
    $tutorUser = $tutor->user;

    // --- 1. the parent registers (the stock sign-up form) -------------------------------------------------------
    test()->post(route('register.store'), [
        'name' => 'Test Parent', 'email' => 'parent@example.test', 'password' => 'a-long-test-password-1', 'password_confirmation' => 'a-long-test-password-1',
    ])->assertSessionHasNoErrors();

    $parent = User::query()->where('email', 'parent@example.test')->sole();
    $parent->forceFill(['email_verified_at' => now()])->save();
    test()->actingAs($parent);

    // --- 2. adds a child ------------------------------------------------------------------------------------------
    test()->post(route('learners.store'), [
        'display_name' => 'Amina',
        'year_group_id' => YearGroup::factory()->create(['curriculum_id' => $curriculum->id])->id,
        'curriculum_id' => $curriculum->id,
        'school' => 'Springfield',
        'notes' => 'Needs help with fractions.',
    ])->assertSessionHasNoErrors();

    $learner = Learner::query()->where('account_user_id', $parent->id)->sole();

    // --- 3. books the trial (the client sends the quote token the page showed it, never a type or a price) ---------------
    $startsAt = '2026-09-15T09:00:00Z';
    $bookingPage = test()->get(route('tutors.book', ['tutor' => $tutor->id, 'starts_at' => $startsAt]))->assertOk();
    $quote = collect($bookingPage->inertiaPage()['props']['learners'])->firstWhere('id', $learner->id);

    test()->post(route('lessons.store'), [
        'learner_id' => $learner->id, 'tutor_id' => $tutor->id, 'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
        'starts_at' => $startsAt, 'quote_token' => $quote['quote_token'],
    ])->assertSessionHasNoErrors();

    $trial = Lesson::query()->sole();
    expect($trial->type)->toBe(LessonType::Trial)
        ->and($trial->status)->toBe(LessonStatus::Confirmed)
        ->and($trial->price->toFils())->toBeLessThan(20000)
        ->and($ledger->balance($trial, LedgerAccount::Escrow))->toBe($trial->price->toFils())
        ->and($ledger->sum($trial))->toBe(0);
    e2eLedgerIsGreen();

    // --- 4. the trial is taught and completes ---------------------------------------------------------------------------
    $trial = e2eTeach($trial);

    // --- 5. the tutor files the trial report, which releases the money ---------------------------------------------------
    test()->actingAs($tutorUser)->post(route('lessons.report.store', $trial), e2eReport(trial: true))
        ->assertSessionHasNoErrors();

    $trial = $trial->fresh();
    expect($trial->status)->toBe(LessonStatus::CompletedReported)
        ->and($trial->escrow_released_at)->not->toBeNull()
        ->and($ledger->balance($trial, LedgerAccount::Escrow))->toBe(0)
        ->and($ledger->balance($trial, LedgerAccount::Tutor))->toBe($trial->tutor_amount->toFils());
    e2eLedgerIsGreen();

    // --- 6. the parent saves a card and sets up a weekly slot -----------------------------------------------------------
    test()->actingAs($parent)->post(route('payment-methods.store'), ['card' => 'succeeds'])->assertSessionHasNoErrors();
    expect(PaymentMethod::query()->where('account_user_id', $parent->id)->sole()->isUsable())->toBeTrue();

    test()->post(route('weekly-slots.store'), [
        'learner_id' => $learner->id, 'tutor_id' => $tutor->id, 'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
        'weekday' => 2, 'start_time' => '10:00', 'starts_on' => '2026-09-22',
    ])->assertSessionHasNoErrors();

    $slot = RecurringSlot::query()->sole();
    expect($slot->status)->toBe(RecurringSlotStatus::Active)
        ->and($slot->price->toFils())->toBe(20000);

    // --- 7. the nightly jobs: generation reserves the lesson (twice is a no-op), then the charge takes it -----------------
    e2eRun('recurring:generate');
    $reserved = Lesson::query()->where('recurring_slot_id', $slot->id)->orderBy('starts_at')->get();
    expect($reserved)->not->toBeEmpty()
        ->and($reserved->first()->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-09-22 10:00')
        ->and($reserved->first()->status)->toBe(LessonStatus::Reserved);
    $count = Lesson::query()->count();
    e2eRun('recurring:generate');
    expect(Lesson::query()->count())->toBe($count);

    $weekly = $reserved->first();
    // The first attempt is due 48 hours before the lesson; the second run charges nothing again.
    test()->travelTo($weekly->starts_at->subHours(48)->addMinutes(5));
    e2eRun('recurring:charge');

    $weekly = $weekly->fresh();
    expect($weekly->status)->toBe(LessonStatus::Confirmed)
        ->and($weekly->type)->toBe(LessonType::Regular)
        ->and($weekly->payment->status)->toBe(PaymentStatus::Captured)
        ->and($weekly->payment->amount->toFils())->toBe(20000)
        ->and($ledger->balance($weekly, LedgerAccount::Escrow))->toBe(20000);
    $entries = LedgerEntry::query()->count();
    e2eRun('recurring:charge');
    expect(LedgerEntry::query()->count())->toBe($entries)
        ->and(Payment::query()->where('lesson_id', $weekly->id)->count())->toBe(1);
    e2eLedgerIsGreen();

    // --- 8. the weekly lesson is taught, reported and released -----------------------------------------------------------
    $weekly = e2eTeach($weekly);

    test()->actingAs($tutorUser)->post(route('lessons.report.store', $weekly), e2eReport(trial: false))
        ->assertSessionHasNoErrors();

    $weekly = $weekly->fresh();
    expect($weekly->status)->toBe(LessonStatus::CompletedReported)
        ->and($ledger->balance($weekly, LedgerAccount::Escrow))->toBe(0)
        ->and($ledger->balance($weekly, LedgerAccount::Tutor))->toBe($weekly->tutor_amount->toFils());
    e2eLedgerIsGreen();

    $earned = $trial->tutor_amount->toFils() + $weekly->tutor_amount->toFils();

    // --- 9. inside the dispute window (R258) the money is released but not yet payable ---------------------------------
    $admin = User::factory()->admin()->create();
    $early = app(GeneratePayoutBatch::class)($admin);
    expect($early->batch)->toBeNull();

    test()->actingAs($tutorUser)->get(route('tutor.earnings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('buckets.paid_to_date', Money::zero()->format($currency)));

    // --- 10. past the window an admin generates the batch and marks the line paid --------------------------------------
    test()->travelTo($weekly->ends_at->addHours(49));
    expect($earned)->toBeGreaterThanOrEqual((int) Settings::get('payout_min'));

    $result = app(GeneratePayoutBatch::class)($admin);
    $line = $result->batch->lines()->where('tutor_profile_id', $tutor->id)->sole();
    expect($line->amount->toFils())->toBe($earned)
        ->and($line->status)->toBe(PayoutLineStatus::Pending);
    e2eLedgerIsGreen();

    app(MarkPayoutLinePaid::class)($admin, $line, 'BANK-REF-E2E');
    expect($line->fresh()->status)->toBe(PayoutLineStatus::Paid);
    e2eLedgerIsGreen();

    // --- 11. the tutor sees it under "paid to date" ----------------------------------------------------------------------
    test()->actingAs($tutorUser)->get(route('tutor.earnings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tutor/Earnings')
            ->where('buckets.pending', Money::zero()->format($currency))
            ->where('buckets.on_hold', Money::zero()->format($currency))
            ->where('buckets.available', Money::zero()->format($currency))
            ->where('buckets.processing', Money::zero()->format($currency))
            ->where('buckets.paid_to_date', Money::fils($earned)->format($currency))
            ->where('buckets.total', Money::fils($earned)->format($currency))
            ->has('payouts', 1));

    // The platform kept its commission and nothing else is owed: every lesson's entries still sum to zero.
    expect($ledger->unbalancedLessons())->toBeEmpty()
        ->and($ledger->unbalancedPayouts())->toBeEmpty();
    e2eLedgerIsGreen();
});
