<?php

use App\Actions\RecordAuditLog;
use App\Actions\RecurringSlots\EndRecurringSlot;
use App\Actions\RecurringSlots\GenerateSlotLessons;
use App\Actions\RecurringSlots\PauseRecurringSlot;
use App\Actions\RecurringSlots\ResumeRecurringSlot;
use App\Enums\AvailabilityExceptionType;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\RecurringSlotSkipReason;
use App\Enums\RecurringSlotStatus;
use App\Enums\SettingGroup;
use App\Enums\TutorProfileStatus;
use App\Mail\RecurringSlots\RecurringSlotSkippedMail;
use App\Models\AuditLog;
use App\Models\AvailabilityException;
use App\Models\Learner;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\RecurringSlot;
use App\Models\RecurringSlotSkip;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Scheduling\SlotCalculator;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/**
 * CP4 (4c): `recurring:generate`. "Now" is Monday 2026-09-14 06:00 UTC and the default horizon is
 * four weeks, so a slot generates from today to 2026-10-12.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC'));
});

/**
 * An active slot with an approved, permitted tutor — a plain factory tutor is not bookable, and
 * every occurrence would then be a skip.
 *
 * @param  array<string, mixed>  $attributes
 */
function rgSlot(array $attributes = []): RecurringSlot
{
    $tutor = TutorProfile::factory()->approved()->create(['hourly_rate' => 10000]);

    return RecurringSlot::factory()->create(array_merge([
        'tutor_profile_id' => $tutor->id,
        'weekday' => 2,
        'start_time' => '17:00',
        'timezone' => 'Asia/Karachi',
        'starts_on' => '2026-09-14',
        'generated_until' => '2026-09-13',
    ], $attributes));
}

function rgRun(): int
{
    return Artisan::call('recurring:generate');
}

/**
 * @return list<string> the slot's lesson start times, UTC, ascending
 */
function rgStarts(RecurringSlot $slot): array
{
    return Lesson::query()->where('recurring_slot_id', $slot->id)->orderBy('starts_at')->get()
        ->map(fn (Lesson $lesson): string => $lesson->starts_at->utc()->format('Y-m-d H:i'))->all();
}

it('generates the horizon in Karachi time, as reserved regular lessons, and is a no-op the second time', function () {
    $slot = rgSlot();

    expect(rgRun())->toBe(0)
        // Reserved lessons with no payment and no ledger entries are not an imbalance.
        ->and(Artisan::call('ledger:verify'))->toBe(0);

    // Tuesdays 17:00 PKT (UTC+5) = 12:00Z, from today to today + 4 weeks (2026-10-12).
    $expected = ['2026-09-15 12:00', '2026-09-22 12:00', '2026-09-29 12:00', '2026-10-06 12:00'];
    expect(rgStarts($slot))->toBe($expected)
        ->and($slot->fresh()->generated_until->toDateString())->toBe('2026-10-12');

    $lesson = Lesson::query()->where('recurring_slot_id', $slot->id)->orderBy('starts_at')->first();
    expect($lesson->status)->toBe(LessonStatus::Reserved)
        ->and($lesson->type)->toBe(LessonType::Regular)
        ->and($lesson->learner_id)->toBe($slot->learner_id)
        ->and($lesson->booked_by_user_id)->toBe($slot->learner->account_user_id)
        ->and($lesson->ends_at->utc()->format('H:i'))->toBe('13:00')
        // A Dubai parent sees 16:00 (UTC+4).
        ->and($lesson->starts_at->setTimezone('Asia/Dubai')->format('H:i'))->toBe('16:00');

    // Box 1: running twice creates nothing new, and moves no money.
    expect(rgRun())->toBe(0)
        ->and(rgStarts($slot))->toBe($expected)
        ->and(Payment::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('freezes the slot price and the policy on each lesson and sets the charge time', function () {
    $slot = rgSlot(['price' => 12345]);

    rgRun();

    $lesson = Lesson::query()->where('recurring_slot_id', $slot->id)->orderBy('starts_at')->first();
    expect($lesson->price->toFils())->toBe(12345)
        ->and($lesson->commission_pct)->toBe(25)
        ->and($lesson->commission_amount->add($lesson->tutor_amount)->toFils())->toBe(12345)
        ->and($lesson->cancel_window_hours)->toBe(24)
        ->and($lesson->student_grace_min)->toBe(15)
        ->and($lesson->tutor_grace_min)->toBe(10)
        ->and($lesson->payment_method_id)->toBeNull()
        // 48 hours before 2026-09-15 12:00Z.
        ->and($lesson->next_charge_at->utc()->format('Y-m-d H:i'))->toBe('2026-09-13 12:00');
});

it('does not touch a generated lesson when settings change later, and freezes new lessons at the new values', function () {
    $slot = rgSlot();
    rgRun();

    Settings::set('commission_pct', 40, SettingGroup::Platform);
    Settings::set('cancel_window_hours', 48, SettingGroup::Platform);
    $this->travelTo(CarbonImmutable::parse('2026-09-21 06:00:00', 'UTC'));
    rgRun();

    $lessons = Lesson::query()->where('recurring_slot_id', $slot->id)->orderBy('starts_at')->get();
    expect($lessons)->toHaveCount(5)
        ->and($lessons->first()->commission_pct)->toBe(25)
        ->and($lessons->first()->cancel_window_hours)->toBe(24)
        ->and($lessons->last()->commission_pct)->toBe(40)
        ->and($lessons->last()->cancel_window_hours)->toBe(48)
        ->and($lessons->last()->price->toFils())->toBe(10000);
});

it('converts each occurrence on its own date across the London clock change', function () {
    Settings::set('recurring_horizon_weeks', 8, SettingGroup::Platform);
    $slot = rgSlot(['weekday' => 1, 'timezone' => 'Europe/London', 'start_time' => '17:00']);

    rgRun();

    $starts = rgStarts($slot);
    // British Summer Time ends Sunday 2026-10-25: Monday 17:00 is 16:00Z before it, 17:00Z after.
    expect($starts)->toContain('2026-10-19 16:00')
        ->toContain('2026-10-26 17:00')
        ->toContain('2026-09-14 16:00');
});

it('leaves no gaps when the job runs every day, east of UTC', function () {
    $slot = rgSlot();
    $all = [];

    for ($day = 0; $day < 15; $day++) {
        $this->travelTo(CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC')->addDays($day));
        rgRun();

        $today = CarbonImmutable::now('Asia/Karachi')->startOfDay();
        expect($slot->fresh()->generated_until->toDateString())->toBe($today->addWeeks(4)->toDateString());
    }

    // Tuesdays from 2026-09-15 to the horizon of the last run (2026-09-28 + 4 weeks = 2026-10-26).
    expect(rgStarts($slot))->toBe([
        '2026-09-15 12:00', '2026-09-22 12:00', '2026-09-29 12:00', '2026-10-06 12:00',
        '2026-10-13 12:00', '2026-10-20 12:00',
    ]);
});

it('generates nothing for a paused or an ended slot', function () {
    $paused = rgSlot(['status' => RecurringSlotStatus::Paused]);
    $ended = rgSlot(['status' => RecurringSlotStatus::Ended, 'ended_at' => now()]);

    rgRun();

    expect(Lesson::query()->count())->toBe(0)
        ->and($paused->fresh()->generated_until->toDateString())->toBe('2026-09-13')
        ->and($ended->fresh()->status)->toBe(RecurringSlotStatus::Ended);
});

it('refills a paused week after a real pause and resume, and nothing after a real end', function () {
    $slot = rgSlot();
    $admin = User::factory()->admin()->create();
    rgRun();

    // 4b's pause frees the (slot, starts_at) key through `slot_paused`; generation stays off while paused.
    app(PauseRecurringSlot::class)($admin, $slot);
    rgRun();
    expect(Lesson::query()->where('recurring_slot_id', $slot->id)->where('status', LessonStatus::Reserved)->count())->toBe(0)
        ->and(Lesson::query()->where('recurring_slot_id', $slot->id)->where('cancel_reason', LessonCancelReason::SlotPaused->value)->count())->toBe(4);

    app(ResumeRecurringSlot::class)($admin, $slot->fresh());
    rgRun();
    expect(Lesson::query()->where('recurring_slot_id', $slot->id)->count())->toBe(8)
        ->and(Lesson::query()->where('recurring_slot_id', $slot->id)->where('status', LessonStatus::Reserved)->count())->toBe(4);

    // An end keeps the key: nothing is regenerated, and the ended slot generates nothing anyway.
    app(EndRecurringSlot::class)($slot->learner->account, $slot->fresh());
    rgRun();
    expect(Lesson::query()->where('recurring_slot_id', $slot->id)->count())->toBe(8)
        ->and(Lesson::query()->where('recurring_slot_id', $slot->id)->where('status', LessonStatus::Reserved)->count())->toBe(0);
});

it('never turns a recorded skip into a lesson when its date is walked again', function () {
    $slot = rgSlot();
    $skip = RecurringSlotSkip::factory()->create(['recurring_slot_id' => $slot->id, 'starts_at' => '2026-09-22 12:00:00']);

    rgRun();

    expect(rgStarts($slot))->toBe(['2026-09-15 12:00', '2026-09-29 12:00', '2026-10-06 12:00'])
        ->and($skip->fresh()->reason)->toBe(RecurringSlotSkipReason::LessonCollision);
});

it('skips an occurrence the tutor cannot take and records why', function () {
    $slot = rgSlot();
    // 2026-09-22 17:00 PKT = 12:00Z: a lesson with another learner. 2026-09-29: a one-hour block,
    // 16:00-17:00 in the tutor's own timezone (Asia/Dubai, UTC+4 = 12:00-13:00Z), which overlaps the
    // occurrence only if the exception is read in the tutor's zone, not the slot's (Karachi would put it at
    // 11:00-12:00Z, which just touches the lesson and blocks nothing).
    Lesson::factory()->withStatus(LessonStatus::Confirmed)->startingAt(CarbonImmutable::parse('2026-09-22 12:00:00', 'UTC'))
        ->create(['tutor_profile_id' => $slot->tutor_profile_id]);
    AvailabilityException::factory()->create([
        'tutor_profile_id' => $slot->tutor_profile_id, 'date' => '2026-09-29',
        'start_time' => '16:00', 'end_time' => '17:00', 'type' => AvailabilityExceptionType::Blocked,
    ]);

    rgRun();

    expect($slot->tutorProfile->user->timezone)->toBe('Asia/Dubai')
        ->and(rgStarts($slot))->toBe(['2026-09-15 12:00', '2026-10-06 12:00'])
        ->and(RecurringSlotSkip::query()->orderBy('starts_at')->get()->map(fn ($s) => [$s->starts_at->utc()->format('Y-m-d H:i'), $s->reason])->all())
        ->toBe([
            ['2026-09-22 12:00', RecurringSlotSkipReason::LessonCollision],
            ['2026-09-29 12:00', RecurringSlotSkipReason::TutorBlocked],
        ])
        ->and($slot->fresh()->status)->toBe(RecurringSlotStatus::Active);
});

it('does not skip an occurrence for a cancelled lesson that freed the tutor slot', function () {
    $slot = rgSlot();
    Lesson::factory()->withStatus(LessonStatus::CancelledByParent)->startingAt(CarbonImmutable::parse('2026-09-22 12:00:00', 'UTC'))
        ->create(['tutor_profile_id' => $slot->tutor_profile_id]);

    rgRun();

    expect(rgStarts($slot))->toContain('2026-09-22 12:00')
        ->and(RecurringSlotSkip::query()->count())->toBe(0);
});

it('skips every occurrence for a tutor who is not bookable, and from the day a permit lapses', function () {
    $suspended = rgSlot();
    $suspended->tutorProfile->forceFill(['status' => TutorProfileStatus::Suspended])->save();

    $lapsing = rgSlot(['weekday' => 3]);
    // Bookable today, permit expires Wednesday 2026-09-30 (UTC midnight, exclusive).
    $lapsing->tutorProfile->forceFill(['permit_expires_at' => '2026-09-30'])->save();

    rgRun();

    expect(Lesson::query()->where('recurring_slot_id', $suspended->id)->count())->toBe(0)
        ->and(RecurringSlotSkip::query()->where('recurring_slot_id', $suspended->id)->count())->toBe(4)
        ->and(RecurringSlotSkip::query()->where('recurring_slot_id', $suspended->id)->pluck('reason')->unique()->all())->toBe([RecurringSlotSkipReason::TutorUnavailable])
        // Wednesdays 09-16 and 09-23 are before the permit expires; 09-30 and 10-07 are not.
        ->and(rgStarts($lapsing))->toBe(['2026-09-16 12:00', '2026-09-23 12:00'])
        ->and(RecurringSlotSkip::query()->where('recurring_slot_id', $lapsing->id)->count())->toBe(2);
});

it('survives a tutor whose user was deleted', function () {
    $slot = rgSlot();
    $slot->tutorProfile->user->delete();

    expect(rgRun())->toBe(0)
        ->and(Lesson::query()->count())->toBe(0)
        ->and(RecurringSlotSkip::query()->where('recurring_slot_id', $slot->id)->count())->toBe(4);
});

it('leaves a slot alone when its learner was deleted', function () {
    $slot = rgSlot();
    $slot->learner->delete();

    expect(rgRun())->toBe(0)
        ->and(Lesson::query()->count())->toBe(0)
        ->and($slot->fresh()->generated_until->toDateString())->toBe('2026-09-13');
});

it('emails the account once about skipped dates, however often the job runs', function () {
    Mail::fake();
    $slot = rgSlot();
    $slot->tutorProfile->forceFill(['status' => TutorProfileStatus::Suspended])->save();

    rgRun();
    rgRun();

    Mail::assertQueued(RecurringSlotSkippedMail::class, 1);
    Mail::assertQueued(RecurringSlotSkippedMail::class, fn (RecurringSlotSkippedMail $mail): bool => $mail->hasTo($slot->learner->account->email)
        && $mail->skips->count() === 4);
    expect(RecurringSlotSkip::query()->whereNull('notified_at')->count())->toBe(0);
});

it('settles a skip without mailing when its learner was deleted', function () {
    Mail::fake();
    $slot = rgSlot();
    RecurringSlotSkip::factory()->create(['recurring_slot_id' => $slot->id, 'starts_at' => '2026-09-22 12:00:00']);
    $slot->learner->delete();

    expect(rgRun())->toBe(0);

    Mail::assertNothingQueued();
    expect(RecurringSlotSkip::query()->whereNull('notified_at')->count())->toBe(0);
});

it('mails a skip that was recorded earlier and unnotified, exactly once, and never one already notified', function () {
    Mail::fake();
    $slot = rgSlot(['status' => RecurringSlotStatus::Paused]);
    RecurringSlotSkip::factory()->create(['recurring_slot_id' => $slot->id, 'starts_at' => '2026-09-22 12:00:00']);
    RecurringSlotSkip::factory()->notified()->create(['recurring_slot_id' => $slot->id, 'starts_at' => '2026-09-29 12:00:00']);

    rgRun();
    rgRun();

    Mail::assertQueued(RecurringSlotSkippedMail::class, 1);
    Mail::assertQueued(RecurringSlotSkippedMail::class, fn (RecurringSlotSkippedMail $mail): bool => $mail->skips->count() === 1);
});

it('ends a slot once its tutor notice has passed, and not before', function () {
    $noticeStillRunning = rgSlot(['end_effective_on' => '2026-09-14', 'ended_by_user_id' => User::factory()->tutor()->create()->id, 'ended_at' => now()]);
    $noticeOver = rgSlot(['end_effective_on' => '2026-09-13', 'ended_by_user_id' => User::factory()->tutor()->create()->id, 'ended_at' => now()->subWeek()]);
    $pausedNoticeOver = rgSlot(['status' => RecurringSlotStatus::Paused, 'end_effective_on' => '2026-09-01', 'ended_by_user_id' => User::factory()->tutor()->create()->id, 'ended_at' => now()->subWeeks(2)]);

    rgRun();

    expect($noticeStillRunning->fresh()->status)->toBe(RecurringSlotStatus::Active)
        // Still inside its notice period on its last day: today's occurrence window only.
        ->and(rgStarts($noticeStillRunning))->toBe([])
        ->and($noticeOver->fresh()->status)->toBe(RecurringSlotStatus::Ended)
        ->and($noticeOver->fresh()->ended_by_user_id)->not->toBeNull()
        ->and($pausedNoticeOver->fresh()->status)->toBe(RecurringSlotStatus::Ended)
        ->and(Lesson::query()->where('recurring_slot_id', $noticeOver->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'recurring_slot.ended')->whereNull('actor_user_id')->count())->toBe(2);
});

it('never generates past the end date, and ends the slot when it has run out', function () {
    $slot = rgSlot(['ends_on' => '2026-09-22']);

    rgRun();
    expect(rgStarts($slot))->toBe(['2026-09-15 12:00', '2026-09-22 12:00'])
        ->and($slot->fresh()->generated_until->toDateString())->toBe('2026-09-22');

    $this->travelTo(CarbonImmutable::parse('2026-09-24 06:00:00', 'UTC'));
    rgRun();

    expect($slot->fresh()->status)->toBe(RecurringSlotStatus::Ended)
        ->and($slot->fresh()->ended_at)->not->toBeNull();
});

it('is on the daily schedule, once at a time on one server', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'recurring:generate'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 5 * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('carries on past a slot that fails and reports it', function () {
    $good = rgSlot();
    $bad = rgSlot(['weekday' => 3]);

    app()->instance(GenerateSlotLessons::class, new class(app(SlotCalculator::class), app(RecordAuditLog::class), $bad->id) extends GenerateSlotLessons
    {
        public function __construct(SlotCalculator $calculator, RecordAuditLog $audit, private readonly int $badId)
        {
            parent::__construct($calculator, $audit);
        }

        public function __invoke(RecurringSlot $slot): array
        {
            if ($slot->id === $this->badId) {
                throw new RuntimeException('boom');
            }

            return parent::__invoke($slot);
        }
    });

    expect(rgRun())->toBe(1)
        ->and(rgStarts($good))->not->toBeEmpty()
        ->and(Lesson::query()->where('recurring_slot_id', $bad->id)->count())->toBe(0);
});

it('renders the skip email in the parent\'s timezone with a reason per date', function () {
    $slot = rgSlot();
    $skips = collect([
        RecurringSlotSkip::factory()->create(['recurring_slot_id' => $slot->id, 'starts_at' => '2026-09-22 12:00:00', 'reason' => RecurringSlotSkipReason::TutorBlocked]),
        RecurringSlotSkip::factory()->create(['recurring_slot_id' => $slot->id, 'starts_at' => '2026-09-29 12:00:00', 'reason' => RecurringSlotSkipReason::LessonCollision]),
    ]);
    $parent = $slot->learner->account;

    $html = (new RecurringSlotSkippedMail($slot, $parent, new Collection($skips->all())))->render();

    expect($html)->toContain('Tuesday, 22 Sep 2026 at 16:00')->toContain('the tutor is away that day')
        ->toContain('the tutor has another lesson at that time')->toContain('Nothing was reserved');
});
