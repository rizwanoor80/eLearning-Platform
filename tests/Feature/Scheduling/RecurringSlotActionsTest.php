<?php

use App\Actions\Lessons\SkipLesson;
use App\Actions\RecurringSlots\CancelSlotReservedLessons;
use App\Actions\RecurringSlots\CreateRecurringSlot;
use App\Actions\RecurringSlots\EndRecurringSlot;
use App\Actions\RecurringSlots\PauseRecurringSlot;
use App\Actions\RecurringSlots\ResumeRecurringSlot;
use App\Enums\CurriculumCode;
use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\LevelTier;
use App\Enums\PaymentMethodStatus;
use App\Enums\RecurringSlotPauseReason;
use App\Enums\RecurringSlotStatus;
use App\Enums\TutorProfileStatus;
use App\Exceptions\RecurringSlotException;
use App\Models\AuditLog;
use App\Models\AvailabilityRule;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PaymentMethod;
use App\Models\PriceBand;
use App\Models\RecurringSlot;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorStrike;
use App\Models\TutorSubject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * CP4 (4b): the slot actions. "Now" is Monday 2026-09-14 06:00 UTC; the default tutor is
 * available Tuesdays 09:00–12:00 in UTC, so the first Tuesday clear of the 12-hour lead is
 * 2026-09-15 and the tests start slots from 2026-09-22.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC'));
});

/**
 * @return array{tutor: TutorProfile, curriculum_id: int, subject_id: int}
 */
function rsTutor(string $timezone = 'UTC', string $from = '09:00:00', string $to = '12:00:00', int $weekday = 2): array
{
    $curriculum = Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0]);
    $subject = Subject::factory()->create();
    $tutor = TutorProfile::factory()->approved()->create(['hourly_rate' => 10000]);

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
        'tutor_profile_id' => $tutor->id, 'weekday' => $weekday, 'start_time' => $from, 'end_time' => $to, 'timezone' => $timezone,
    ]);

    return ['tutor' => $tutor->fresh(), 'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id];
}

/**
 * A parent with a saved card and a learner who has completed a trial with the tutor.
 *
 * @return array{parent: User, learner: Learner}
 */
function rsParent(TutorProfile $tutor, bool $trial = true, bool $card = true): array
{
    $parent = User::factory()->create();
    $learner = rsLearner(['account_user_id' => $parent->id]);

    if ($card) {
        PaymentMethod::factory()->create(['account_user_id' => $parent->id]);
    }

    if ($trial) {
        $GLOBALS['rsTrialSeq'] = ($GLOBALS['rsTrialSeq'] ?? 0) + 1;
        Lesson::factory()->trial()->withStatus(LessonStatus::Completed)->create([
            'tutor_profile_id' => $tutor->id,
            'learner_id' => $learner->id,
            'starts_at' => now()->subDays(3)->subHours($n = $GLOBALS['rsTrialSeq']),
            'ends_at' => now()->subDays(3)->subHours($n)->addHour(),
        ]);
    }

    return ['parent' => $parent, 'learner' => $learner];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function rsData(array $setup, array $extra = []): array
{
    return array_merge([
        'curriculum_id' => $setup['curriculum_id'],
        'subject_id' => $setup['subject_id'],
        'weekday' => 2,
        'start_time' => '10:00',
        'starts_on' => '2026-09-22',
    ], $extra);
}

function rsLearner(array $attributes = []): Learner
{
    return Learner::factory()->create(array_merge([
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ], $attributes));
}

function rsAdmin(): User
{
    return User::factory()->admin()->create();
}

function rsSlot(TutorProfile $tutor, Learner $learner, array $attributes = []): RecurringSlot
{
    return RecurringSlot::factory()->create(array_merge([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'weekday' => 2,
        'start_time' => '10:00',
        'timezone' => 'UTC',
        'starts_on' => '2026-09-22',
        'generated_until' => '2026-09-21',
    ], $attributes));
}

function rsLesson(RecurringSlot $slot, string $startsAt, LessonStatus $status = LessonStatus::Reserved, array $attributes = []): Lesson
{
    $start = CarbonImmutable::parse($startsAt, 'UTC');

    return Lesson::factory()->withStatus($status)->create(array_merge([
        'tutor_profile_id' => $slot->tutor_profile_id,
        'learner_id' => $slot->learner_id,
        'recurring_slot_id' => $slot->id,
        'starts_at' => $start,
        'ends_at' => $start->addHour(),
    ], $attributes));
}

// ── CreateRecurringSlot ───────────────────────────────────────────────────────────────────

it('creates a weekly slot with the tutor rate frozen on it, the rule timezone and an audit row', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);

    $slot = app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup, ['ends_on' => '2026-12-22']));

    expect($slot->status)->toBe(RecurringSlotStatus::Active)
        ->and($slot->price->toFils())->toBe(10000)
        ->and($slot->timezone)->toBe('UTC')
        ->and($slot->weekday)->toBe(2)
        ->and(substr($slot->start_time, 0, 5))->toBe('10:00')
        ->and($slot->generated_until->toDateString())->toBe('2026-09-21')
        ->and($slot->ends_on->toDateString())->toBe('2026-12-22')
        ->and($slot->created_by_user_id)->toBe($parent->id);

    $audit = AuditLog::query()->where('action', 'recurring_slot.created')->sole();
    expect($audit->subject_id)->toBe($slot->id)
        ->and($audit->after['trial_override'])->toBeFalse()
        ->and($audit->after['override_reason'])->toBeNull();
});

it('freezes the price on the slot: a later rate or band change leaves it alone', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);
    $slot = app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup));

    $setup['tutor']->forceFill(['hourly_rate' => 12000])->save();
    PriceBand::query()->update(['min_rate' => 11000, 'max_rate' => 13000]);

    expect($slot->fresh()->price->toFils())->toBe(10000);
});

it('refuses a slot before the trial lesson is completed, and a trial that is merely confirmed', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor'], trial: false);

    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup)))
        ->toThrow(RecurringSlotException::class, 'trial');

    Lesson::factory()->trial()->withStatus(LessonStatus::Confirmed)->create([
        'tutor_profile_id' => $setup['tutor']->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addDays(1),
        'ends_at' => now()->addDays(1)->addHour(),
    ]);

    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup)))
        ->toThrow(RecurringSlotException::class, 'trial');

    expect(RecurringSlot::query()->count())->toBe(0);
});

it('lets an admin override the trial requirement only with a typed reason, and audits it', function () {
    $setup = rsTutor();
    ['learner' => $learner] = rsParent($setup['tutor'], trial: false);
    $admin = rsAdmin();

    expect(fn () => app(CreateRecurringSlot::class)($admin, $learner, $setup['tutor'], rsData($setup), '   '))
        ->toThrow(RecurringSlotException::class);

    $slot = app(CreateRecurringSlot::class)($admin, $learner, $setup['tutor'], rsData($setup), 'Trial done offline, confirmed by phone');

    expect($slot->created_by_user_id)->toBe($admin->id);

    $audit = AuditLog::query()->where('action', 'recurring_slot.created')->sole();
    expect($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->after['trial_override'])->toBeTrue()
        ->and($audit->after['override_reason'])->toBe('Trial done offline, confirmed by phone');
});

it('does not let a parent use the override', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor'], trial: false);

    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup), 'please'))
        ->toThrow(RecurringSlotException::class);

    expect(RecurringSlot::query()->count())->toBe(0);
});

it('still requires a usable saved card on the override path', function () {
    $setup = rsTutor();
    ['learner' => $learner] = rsParent($setup['tutor'], trial: false, card: false);

    expect(fn () => app(CreateRecurringSlot::class)(rsAdmin(), $learner, $setup['tutor'], rsData($setup), 'reason'))
        ->toThrow(RecurringSlotException::class, 'card');
});

it('refuses a missing, failed or expired card', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor'], card: false);

    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup)))
        ->toThrow(RecurringSlotException::class, 'card');

    $card = PaymentMethod::factory()->create(['account_user_id' => $parent->id, 'status' => PaymentMethodStatus::Failed]);
    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup)))
        ->toThrow(RecurringSlotException::class, 'card');

    $card->update(['status' => PaymentMethodStatus::Active, 'exp_month' => 8, 'exp_year' => 2026]);
    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup)))
        ->toThrow(RecurringSlotException::class, 'card');

    // Good through the last day of its month.
    $card->update(['exp_month' => 9, 'exp_year' => 2026]);
    expect(app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup)))->toBeInstanceOf(RecurringSlot::class);
});

it('refuses a tutor who is not bookable', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);

    $setup['tutor']->forceFill(['status' => TutorProfileStatus::Suspended])->save();
    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup)))
        ->toThrow(RecurringSlotException::class, 'not currently bookable');

    $setup['tutor']->forceFill(['status' => TutorProfileStatus::Approved, 'permit_expires_at' => now()->subDay()])->save();
    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup)))
        ->toThrow(RecurringSlotException::class, 'not currently bookable');
});

it('refuses a tutor whose rate sits outside the price band', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);
    $setup['tutor']->forceFill(['hourly_rate' => 90000])->save();

    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup)))
        ->toThrow(RecurringSlotException::class, 'band');
});

it('refuses a subject the tutor does not teach', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);

    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup, ['subject_id' => Subject::factory()->create()->id])))
        ->toThrow(RecurringSlotException::class, 'does not teach');
});

it('refuses a time that is off the hour or outside the tutor availability', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);
    $create = fn (array $extra) => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup, $extra));

    expect(fn () => $create(['start_time' => '10:30']))->toThrow(RecurringSlotException::class, 'on the hour');
    expect(fn () => $create(['start_time' => '12:00']))->toThrow(RecurringSlotException::class, 'not available');
    expect(fn () => $create(['start_time' => '08:00']))->toThrow(RecurringSlotException::class, 'not available');
    expect(fn () => $create(['weekday' => 3]))->toThrow(RecurringSlotException::class, 'not available');
    expect(fn () => $create(['weekday' => 9]))->toThrow(RecurringSlotException::class);

    // The last hour of the rule is bookable.
    expect($create(['start_time' => '11:00']))->toBeInstanceOf(RecurringSlot::class);
});

it('refuses a first lesson inside the minimum lead time', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);

    // Monday 06:00 now, first Tuesday 09:00 UTC is 27h out (fine); with a 12h lead a Monday start is too soon.
    $setup2 = rsTutor('UTC', '07:00:00', '12:00:00', 1);
    ['parent' => $parent2, 'learner' => $learner2] = rsParent($setup2['tutor']);

    expect(fn () => app(CreateRecurringSlot::class)($parent2, $learner2, $setup2['tutor'], rsData($setup2, ['weekday' => 1, 'start_time' => '07:00', 'starts_on' => '2026-09-14'])))
        ->toThrow(RecurringSlotException::class, 'at least');

    expect(app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup, ['starts_on' => '2026-09-15'])))->toBeInstanceOf(RecurringSlot::class);
});

it('refuses an end date before the start or before the first lesson', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);

    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup, ['ends_on' => '2026-09-01'])))
        ->toThrow(RecurringSlotException::class, 'end date');

    // Starts on a Wednesday: the first Tuesday is the 29th, past this end date.
    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup, ['starts_on' => '2026-09-23', 'ends_on' => '2026-09-25'])))
        ->toThrow(RecurringSlotException::class, 'end date');
});

it('refuses a slot that overlaps a lesson the tutor already has, but not one that was cancelled', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);

    $other = rsLearner();
    Lesson::factory()->withStatus(LessonStatus::Confirmed)->create([
        'tutor_profile_id' => $setup['tutor']->id, 'learner_id' => $other->id,
        'starts_at' => CarbonImmutable::parse('2026-10-06 10:30:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-10-06 11:30:00', 'UTC'),
    ]);

    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup)))
        ->toThrow(RecurringSlotException::class, 'overlaps');

    // Ending on the 29th leaves the 6 October lesson beyond the slot.
    expect(app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup, ['ends_on' => '2026-09-29'])))->toBeInstanceOf(RecurringSlot::class);
});

it('reads the collision check in the slot timezone across the London clock change', function () {
    $setup = rsTutor('Europe/London', '16:00:00', '19:00:00');
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);
    $other = rsLearner();
    $book = fn (string $start) => Lesson::factory()->withStatus(LessonStatus::Confirmed)->create([
        'tutor_profile_id' => $setup['tutor']->id, 'learner_id' => $other->id,
        'starts_at' => CarbonImmutable::parse($start, 'UTC'), 'ends_at' => CarbonImmutable::parse($start, 'UTC')->addHour(),
    ]);
    $create = fn (string $day) => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup, ['start_time' => '17:00', 'starts_on' => $day, 'ends_on' => $day]));

    // Tue 20 Oct: London is on BST (UTC+1), so 17:00 local is 16:00 UTC. A lesson at 17:00 UTC is clear of it...
    $book('2026-10-20 17:00:00');
    expect($create('2026-10-20'))->toBeInstanceOf(RecurringSlot::class);
    RecurringSlot::query()->delete();

    // ...and one at 16:00 UTC is not.
    $book('2026-10-20 16:00:00');
    expect(fn () => $create('2026-10-20'))->toThrow(RecurringSlotException::class, 'overlaps');

    // Tue 27 Oct: the clocks went back on the 25th, so 17:00 local is 17:00 UTC.
    $book('2026-10-27 16:00:00');
    expect($create('2026-10-27'))->toBeInstanceOf(RecurringSlot::class);
    RecurringSlot::query()->delete();

    $book('2026-10-27 17:00:00');
    expect(fn () => $create('2026-10-27'))->toThrow(RecurringSlotException::class, 'overlaps');
});

it('reads the collision check in the slot timezone for Asia/Karachi', function () {
    $setup = rsTutor('Asia/Karachi', '16:00:00', '19:00:00');
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);
    Lesson::factory()->withStatus(LessonStatus::Confirmed)->create([
        'tutor_profile_id' => $setup['tutor']->id, 'learner_id' => rsLearner()->id,
        'starts_at' => CarbonImmutable::parse('2026-09-22 12:00:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-09-22 13:00:00', 'UTC'),
    ]);

    // Tue 17:00 Karachi (UTC+5, no DST) is 12:00 UTC.
    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup, ['start_time' => '17:00', 'ends_on' => '2026-09-22'])))
        ->toThrow(RecurringSlotException::class, 'overlaps');

    $slot = app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup, ['start_time' => '18:00', 'ends_on' => '2026-09-22']));
    expect($slot->timezone)->toBe('Asia/Karachi');
});

it('turns the two-parents race into a clean refusal, and leaves the first slot intact', function () {
    $setup = rsTutor();
    ['parent' => $parentA, 'learner' => $learnerA] = rsParent($setup['tutor']);
    ['parent' => $parentB, 'learner' => $learnerB] = rsParent($setup['tutor']);

    $first = app(CreateRecurringSlot::class)($parentA, $learnerA, $setup['tutor'], rsData($setup));

    expect(fn () => app(CreateRecurringSlot::class)($parentB, $learnerB, $setup['tutor'], rsData($setup)))
        ->toThrow(RecurringSlotException::class, 'already has a weekly slot');

    expect(RecurringSlot::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'recurring_slot.created')->count())->toBe(1)
        ->and($first->fresh()->status)->toBe(RecurringSlotStatus::Active);

    // A paused slot still holds the place; an ended one frees it.
    $first->forceFill(['status' => RecurringSlotStatus::Paused])->save();
    expect(fn () => app(CreateRecurringSlot::class)($parentB, $learnerB, $setup['tutor'], rsData($setup)))
        ->toThrow(RecurringSlotException::class, 'already has a weekly slot');

    $first->forceFill(['status' => RecurringSlotStatus::Ended])->save();
    expect(app(CreateRecurringSlot::class)($parentB, $learnerB, $setup['tutor'], rsData($setup)))->toBeInstanceOf(RecurringSlot::class);
});

it('refuses another parent, a tutor, a stranger admin-less caller and a removed learner', function () {
    $setup = rsTutor();
    ['learner' => $learner] = rsParent($setup['tutor']);
    ['parent' => $stranger] = rsParent($setup['tutor']);

    expect(fn () => app(CreateRecurringSlot::class)($stranger, $learner, $setup['tutor'], rsData($setup)))->toThrow(RecurringSlotException::class);
    expect(fn () => app(CreateRecurringSlot::class)($setup['tutor']->user, $learner, $setup['tutor'], rsData($setup)))->toThrow(RecurringSlotException::class);

    $learner->delete();
    expect(fn () => app(CreateRecurringSlot::class)(rsAdmin(), $learner, $setup['tutor'], rsData($setup), 'reason'))->toThrow(RecurringSlotException::class, 'removed');
});

// ── EndRecurringSlot ──────────────────────────────────────────────────────────────────────

it('ends a slot for the parent at once: reserved lessons cancelled free, confirmed ones left', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);
    $slot = rsSlot($setup['tutor'], $learner);
    $reservedA = rsLesson($slot, '2026-09-22 10:00:00');
    $reservedB = rsLesson($slot, '2026-09-29 10:00:00');
    $confirmed = rsLesson($slot, '2026-10-06 10:00:00', LessonStatus::Confirmed);

    app(EndRecurringSlot::class)($parent, $slot, 'moving away');

    $slot->refresh();
    expect($slot->status)->toBe(RecurringSlotStatus::Ended)
        ->and($slot->ended_by_user_id)->toBe($parent->id)
        ->and($slot->ended_at)->not->toBeNull();

    foreach ([$reservedA, $reservedB] as $lesson) {
        $lesson->refresh();
        expect($lesson->status)->toBe(LessonStatus::CancelledByParent)
            ->and($lesson->cancel_reason)->toBe(LessonCancelReason::SlotEnded->value)
            ->and($lesson->cancelled_by_user_id)->toBe($parent->id);
    }

    expect($confirmed->fresh()->status)->toBe(LessonStatus::Confirmed)
        ->and(TutorStrike::query()->count())->toBe(0);

    $audit = AuditLog::query()->where('action', 'recurring_slot.ended')->sole();
    expect($audit->after['cancelled_lessons'])->toBe(2)->and($audit->after['note'])->toBe('moving away');
});

it('ends a slot for an admin at once', function () {
    $setup = rsTutor();
    ['learner' => $learner] = rsParent($setup['tutor']);
    $slot = rsSlot($setup['tutor'], $learner);
    $lesson = rsLesson($slot, '2026-09-22 10:00:00');

    app(EndRecurringSlot::class)(rsAdmin(), $slot);

    expect($slot->fresh()->status)->toBe(RecurringSlotStatus::Ended)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::CancelledByParent);
});

it('gives the tutor a notice period: only reserved lessons beyond it are cancelled, with no strike', function () {
    $setup = rsTutor();
    ['learner' => $learner] = rsParent($setup['tutor']);
    // Now is Mon 14 Sep; a 7-day notice runs to Mon 21 Sep, so the last local day is the 21st.
    $slot = rsSlot($setup['tutor'], $learner, ['starts_on' => '2026-09-15', 'generated_until' => '2026-09-14']);
    $day3 = rsLesson($slot, '2026-09-17 10:00:00');
    $day7 = rsLesson($slot, '2026-09-21 10:00:00');
    $day8 = rsLesson($slot, '2026-09-22 10:00:00');
    $later = rsLesson($slot, '2026-09-29 10:00:00');
    $paid = rsLesson($slot, '2026-10-06 10:00:00', LessonStatus::Confirmed);

    app(EndRecurringSlot::class)($setup['tutor']->user, $slot);

    $slot->refresh();
    expect($slot->status)->toBe(RecurringSlotStatus::Active)
        ->and($slot->end_effective_on->toDateString())->toBe('2026-09-21')
        ->and($slot->ended_by_user_id)->toBe($setup['tutor']->user_id)
        ->and($slot->effectiveEndDate()->toDateString())->toBe('2026-09-21');

    expect($day3->fresh()->status)->toBe(LessonStatus::Reserved)
        ->and($day7->fresh()->status)->toBe(LessonStatus::Reserved)
        ->and($paid->fresh()->status)->toBe(LessonStatus::Confirmed);

    foreach ([$day8, $later] as $lesson) {
        $lesson->refresh();
        expect($lesson->status)->toBe(LessonStatus::CancelledByTutor)
            ->and($lesson->cancel_reason)->toBe(LessonCancelReason::SlotEnded->value);
    }

    expect(TutorStrike::query()->count())->toBe(0);
});

it('strikes a tutor who then skips a lesson inside the notice period only when it is inside the window', function () {
    $setup = rsTutor();
    ['learner' => $learner] = rsParent($setup['tutor']);
    $slot = rsSlot($setup['tutor'], $learner, ['starts_on' => '2026-09-15', 'generated_until' => '2026-09-14']);
    $day3 = rsLesson($slot, '2026-09-17 10:00:00');
    $tomorrow = rsLesson($slot, '2026-09-15 04:00:00');

    app(EndRecurringSlot::class)($setup['tutor']->user, $slot);

    // Day 3 is more than 24 hours out: free, no strike.
    app(SkipLesson::class)($setup['tutor']->user, $day3->fresh());
    expect(TutorStrike::query()->count())->toBe(0);

    // 22 hours out: inside the frozen 24-hour window, so it strikes.
    app(SkipLesson::class)($setup['tutor']->user, $tomorrow->fresh());
    expect(TutorStrike::query()->where('lesson_id', $tomorrow->id)->count())->toBe(1);
});

it('refuses a second notice from the tutor, another tutor, and any end of an ended slot', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);
    $slot = rsSlot($setup['tutor'], $learner);

    $otherTutor = TutorProfile::factory()->approved()->create();
    expect(fn () => app(EndRecurringSlot::class)($otherTutor->user, $slot))->toThrow(RecurringSlotException::class);
    expect(fn () => app(EndRecurringSlot::class)(User::factory()->create(), $slot))->toThrow(RecurringSlotException::class);

    app(EndRecurringSlot::class)($setup['tutor']->user, $slot);
    expect(fn () => app(EndRecurringSlot::class)($setup['tutor']->user, $slot->fresh()))->toThrow(RecurringSlotException::class, 'already');

    // The parent can still end it at once after the tutor's notice.
    app(EndRecurringSlot::class)($parent, $slot->fresh());
    expect($slot->fresh()->status)->toBe(RecurringSlotStatus::Ended);
    expect(fn () => app(EndRecurringSlot::class)($parent, $slot->fresh()))->toThrow(RecurringSlotException::class, 'already ended');
});

it('leaves a lesson that was charged between the query and the lock untouched', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);
    $slot = rsSlot($setup['tutor'], $learner);
    $lesson = rsLesson($slot, '2026-09-22 10:00:00');

    // Simulates the race: the row is `confirmed` by the time the bulk cancel locks it.
    Lesson::allowingStatusWrites(fn () => Lesson::query()->whereKey($lesson->id)->update(['status' => LessonStatus::Confirmed]));

    $cancelled = app(CancelSlotReservedLessons::class)($slot, LessonStatus::CancelledByParent, $parent, LessonCancelReason::SlotEnded);

    expect($cancelled)->toBe(0)->and($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
});

// ── Pause and resume ──────────────────────────────────────────────────────────────────────

it('pauses a slot for an admin: reserved lessons cancelled free as slot_paused, confirmed left, audited', function () {
    $setup = rsTutor();
    ['learner' => $learner] = rsParent($setup['tutor']);
    $slot = rsSlot($setup['tutor'], $learner);
    $reserved = rsLesson($slot, '2026-09-22 10:00:00');
    $confirmed = rsLesson($slot, '2026-09-29 10:00:00', LessonStatus::Confirmed);
    $admin = rsAdmin();

    app(PauseRecurringSlot::class)($admin, $slot, note: 'card query');

    $slot->refresh();
    expect($slot->status)->toBe(RecurringSlotStatus::Paused)
        ->and($slot->paused_reason)->toBe(RecurringSlotPauseReason::Admin);

    $reserved->refresh();
    expect($reserved->status)->toBe(LessonStatus::CancelledByParent)
        ->and($reserved->cancel_reason)->toBe(LessonCancelReason::SlotPaused->value)
        ->and($reserved->cancelled_by_user_id)->toBe($admin->id)
        ->and($confirmed->fresh()->status)->toBe(LessonStatus::Confirmed);

    $audit = AuditLog::query()->where('action', 'recurring_slot.paused')->sole();
    expect($audit->actor_user_id)->toBe($admin->id)->and($audit->after['cancelled_lessons'])->toBe(1);
});

it('refuses a pause by anyone but an admin, and a pause of a slot that is not active', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);
    $slot = rsSlot($setup['tutor'], $learner);

    expect(fn () => app(PauseRecurringSlot::class)($parent, $slot))->toThrow(RecurringSlotException::class);
    expect(fn () => app(PauseRecurringSlot::class)($setup['tutor']->user, $slot))->toThrow(RecurringSlotException::class);
    expect(fn () => app(PauseRecurringSlot::class)(null, $slot))->toThrow(RecurringSlotException::class);

    app(PauseRecurringSlot::class)(rsAdmin(), $slot);
    expect(fn () => app(PauseRecurringSlot::class)(rsAdmin(), $slot->fresh()))->toThrow(RecurringSlotException::class, 'active');
});

it('lets the system pause with no actor for a payment failure, and refuses a person doing so', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);
    $slot = rsSlot($setup['tutor'], $learner);

    expect(fn () => app(PauseRecurringSlot::class)($parent, $slot, RecurringSlotPauseReason::PaymentFailed))->toThrow(RecurringSlotException::class);

    app(PauseRecurringSlot::class)(null, $slot, RecurringSlotPauseReason::PaymentFailed);

    expect($slot->fresh()->paused_reason)->toBe(RecurringSlotPauseReason::PaymentFailed)
        ->and(AuditLog::query()->where('action', 'recurring_slot.paused')->sole()->actor_user_id)->toBeNull();
});

it('frees the lesson key on pause so a resumed slot can regenerate the dates, and resume resets the counters', function () {
    $setup = rsTutor();
    ['learner' => $learner] = rsParent($setup['tutor']);
    $slot = rsSlot($setup['tutor'], $learner, ['consecutive_charge_failures' => 2, 'generated_until' => '2026-10-13']);
    rsLesson($slot, '2026-09-22 10:00:00');
    $admin = rsAdmin();

    app(PauseRecurringSlot::class)($admin, $slot);

    // The pause released (recurring_slot_id, starts_at): regenerating the same date inserts cleanly.
    $regenerated = rsLesson($slot, '2026-09-22 10:00:00');
    expect($regenerated->exists)->toBeTrue();

    app(ResumeRecurringSlot::class)($admin, $slot->fresh(), 'card updated');

    $slot->refresh();
    expect($slot->status)->toBe(RecurringSlotStatus::Active)
        ->and($slot->paused_reason)->toBeNull()
        ->and($slot->consecutive_charge_failures)->toBe(0)
        ->and($slot->generated_until->toDateString())->toBe('2026-09-14');

    expect(AuditLog::query()->where('action', 'recurring_slot.resumed')->sole()->actor_user_id)->toBe($admin->id);
});

it('does not free the lesson key when a slot is ended', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);
    $slot = rsSlot($setup['tutor'], $learner);
    rsLesson($slot, '2026-09-22 10:00:00');

    app(EndRecurringSlot::class)($parent, $slot);

    expect(fn () => DB::transaction(fn () => rsLesson($slot, '2026-09-22 10:00:00')))->toThrow(QueryException::class);
});

it('refuses to resume a slot that is active, or past its end date, or by a non-admin', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);
    $active = rsSlot($setup['tutor'], $learner);

    expect(fn () => app(ResumeRecurringSlot::class)(rsAdmin(), $active))->toThrow(RecurringSlotException::class, 'paused');

    app(PauseRecurringSlot::class)(rsAdmin(), $active);
    expect(fn () => app(ResumeRecurringSlot::class)($parent, $active->fresh()))->toThrow(RecurringSlotException::class);

    $active->forceFill(['ends_on' => '2026-09-13'])->save();
    expect(fn () => app(ResumeRecurringSlot::class)(rsAdmin(), $active->fresh()))->toThrow(RecurringSlotException::class, 'end date');
});

// ── Policy ────────────────────────────────────────────────────────────────────────────────

it('scopes viewing to the parent, the tutor and admins, and pause/resume to admins', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);
    $slot = rsSlot($setup['tutor'], $learner);
    $admin = rsAdmin();
    $stranger = User::factory()->create();
    $otherTutor = TutorProfile::factory()->approved()->create()->user;

    expect($admin->can('view', $slot))->toBeTrue()
        ->and($parent->can('view', $slot))->toBeTrue()
        ->and($setup['tutor']->user->can('view', $slot))->toBeTrue()
        ->and($stranger->can('view', $slot))->toBeFalse()
        ->and($otherTutor->can('view', $slot))->toBeFalse()
        ->and($parent->can('pause', $slot))->toBeFalse()
        ->and($setup['tutor']->user->can('resume', $slot))->toBeFalse()
        ->and($admin->can('pause', $slot))->toBeTrue()
        ->and($admin->can('resume', $slot))->toBeTrue()
        ->and($admin->can('update', $slot))->toBeFalse()
        ->and($admin->can('delete', $slot))->toBeFalse()
        ->and($parent->can('viewAny', RecurringSlot::class))->toBeFalse()
        ->and($admin->can('viewAny', RecurringSlot::class))->toBeTrue();
});

it('lets the parent of a removed learner still see the slot', function () {
    $setup = rsTutor();
    ['parent' => $parent, 'learner' => $learner] = rsParent($setup['tutor']);
    $slot = rsSlot($setup['tutor'], $learner);
    $learner->delete();

    expect($parent->can('view', $slot->fresh()))->toBeTrue();
});

it('records the lesson type of the trial prerequisite as a trial only', function () {
    $setup = rsTutor();
    $parent = User::factory()->create();
    $learner = rsLearner(['account_user_id' => $parent->id]);
    PaymentMethod::factory()->create(['account_user_id' => $parent->id]);

    // A completed REGULAR lesson is not a trial.
    Lesson::factory()->withStatus(LessonStatus::Completed)->create([
        'type' => LessonType::Regular,
        'tutor_profile_id' => $setup['tutor']->id, 'learner_id' => $learner->id,
        'starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHour(),
    ]);

    expect(fn () => app(CreateRecurringSlot::class)($parent, $learner, $setup['tutor'], rsData($setup)))
        ->toThrow(RecurringSlotException::class, 'trial');
});
