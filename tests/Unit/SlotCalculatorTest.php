<?php

use App\Enums\AvailabilityExceptionType;
use App\Enums\LessonStatus;
use App\Enums\RecurringSlotStatus;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Lesson;
use App\Models\RecurringSlot;
use App\Services\Scheduling\Slot;
use App\Services\Scheduling\SlotCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

// Pure core: no database, only in-memory models. 2026-09-14 is a Monday;
// weekday 0 = Sunday. Dubai is UTC+4 all year.
uses(TestCase::class);

function scRule(int $weekday, string $start, string $end, string $tz = 'Asia/Dubai'): AvailabilityRule
{
    return (new AvailabilityRule)->forceFill(['weekday' => $weekday, 'start_time' => $start, 'end_time' => $end, 'timezone' => $tz]);
}

function scException(string $date, string $start, string $end, AvailabilityExceptionType $type): AvailabilityException
{
    return (new AvailabilityException)->forceFill(['date' => $date, 'start_time' => $start, 'end_time' => $end, 'type' => $type]);
}

function scLesson(string $startUtc, LessonStatus $status = LessonStatus::Confirmed, int $minutes = 60): Lesson
{
    $start = CarbonImmutable::parse($startUtc, 'UTC');

    return (new Lesson)->forceFill(['starts_at' => $start, 'ends_at' => $start->addMinutes($minutes), 'status' => $status]);
}

function scWeekly(int $weekday, string $start, string $tz, string $from, ?string $until = null, RecurringSlotStatus $status = RecurringSlotStatus::Active, ?string $endEffective = null): RecurringSlot
{
    return (new RecurringSlot)->forceFill(['weekday' => $weekday, 'start_time' => $start, 'timezone' => $tz, 'starts_on' => $from, 'ends_on' => $until, 'end_effective_on' => $endEffective, 'status' => $status]);
}

/**
 * @param  array<int, mixed>  $overrides
 * @return list<Slot>
 */
function scSlots(array $overrides = []): array
{
    $a = array_merge([
        'rules' => [], 'exceptions' => [], 'lessons' => [], 'recurring' => [],
        'now' => '2026-09-14 06:00:00', 'lead' => 12, 'max' => 7,
        'exceptionTz' => 'Asia/Dubai', 'outTz' => 'Asia/Dubai',
    ], $overrides);

    return (new SlotCalculator)->calculate(
        $a['rules'], $a['exceptions'], $a['lessons'], $a['recurring'],
        CarbonImmutable::parse($a['now'], 'UTC'), $a['lead'], $a['max'], $a['exceptionTz'], $a['outTz'],
    );
}

/**
 * @param  list<Slot>  $slots
 * @return list<string>
 */
function scStarts(array $slots, string $tz = 'UTC'): array
{
    return array_map(fn (Slot $s): string => $s->startsAt->setTimezone($tz)->format('Y-m-d H:i'), $slots);
}

it('returns nothing without any availability', function () {
    expect(scSlots())->toBe([]);
});

it('steps hourly slots from the rule start time, not the wall-clock hour', function () {
    // Tuesday 09:30–12:30 Dubai: 09:30, 10:30, 11:30 (a 12:30 slot would overrun).
    expect(scStarts(scSlots(['rules' => [scRule(2, '09:30', '12:30')]]), 'Asia/Dubai'))
        ->toBe(['2026-09-15 09:30', '2026-09-15 10:30', '2026-09-15 11:30']);
});

it('does not offer a slot that does not fit before the rule ends', function () {
    expect(scStarts(scSlots(['rules' => [scRule(2, '09:00', '10:30')]]), 'Asia/Dubai'))->toBe(['2026-09-15 09:00']);
});

it('reads database-style H:i:s times', function () {
    expect(scStarts(scSlots(['rules' => [scRule(2, '09:00:00', '10:00:00')]]), 'Asia/Dubai'))->toBe(['2026-09-15 09:00']);
});

it('returns slots in the requested timezone', function () {
    $result = scSlots(['rules' => [scRule(2, '09:00', '10:00')], 'outTz' => 'Europe/London']);

    expect($result[0]->startsAt->timezoneName)->toBe('Europe/London')
        ->and($result[0]->startsAt->format('H:i'))->toBe('06:00') // 05:00 UTC, BST
        ->and($result[0]->endsAt->format('H:i'))->toBe('07:00');
});

it('excludes a blocked exception by interval overlap', function () {
    $result = scSlots([
        'rules' => [scRule(2, '09:30', '12:30')],
        'exceptions' => [scException('2026-09-15', '10:00', '11:00', AvailabilityExceptionType::Blocked)],
    ]);

    // The block touches the 09:30 and 10:30 slots; only 11:30 survives.
    expect(scStarts($result, 'Asia/Dubai'))->toBe(['2026-09-15 11:30']);
});

it('includes an extra exception on a day without a rule', function () {
    $result = scSlots(['exceptions' => [scException('2026-09-16', '14:00', '16:00', AvailabilityExceptionType::Extra)]]);

    expect(scStarts($result, 'Asia/Dubai'))->toBe(['2026-09-16 14:00', '2026-09-16 15:00']);
});

it('does not duplicate a slot offered by both a rule and an extra exception', function () {
    $result = scSlots([
        'rules' => [scRule(2, '09:00', '10:00')],
        'exceptions' => [scException('2026-09-15', '09:00', '10:00', AvailabilityExceptionType::Extra)],
    ]);

    expect($result)->toHaveCount(1);
});

it('reads exceptions in the given exception timezone, not the rule timezone', function () {
    $extra = [scException('2026-09-16', '14:00', '15:00', AvailabilityExceptionType::Extra)];

    expect(scStarts(scSlots(['exceptions' => $extra, 'exceptionTz' => 'Asia/Dubai']))) // 14:00 Dubai
        ->toBe(['2026-09-16 10:00'])
        ->and(scStarts(scSlots(['exceptions' => $extra, 'exceptionTz' => 'Asia/Karachi'])))
        ->toBe(['2026-09-16 09:00']);
});

it('excludes booked lessons by interval overlap, not start equality', function () {
    // Tuesday 10:00–12:00 Dubai (slots 10:00, 11:00); a lesson 10:30–11:30 Dubai = 06:30–07:30 UTC.
    $result = scSlots([
        'rules' => [scRule(2, '10:00', '12:00')],
        'lessons' => [scLesson('2026-09-15 06:30:00')],
    ]);

    expect($result)->toBe([]);
});

it('keeps a slot the lesson only touches at the boundary', function () {
    $result = scSlots([
        'rules' => [scRule(2, '10:00', '12:00')],
        'lessons' => [scLesson('2026-09-15 06:00:00')], // exactly 10:00–11:00 Dubai
    ]);

    expect(scStarts($result, 'Asia/Dubai'))->toBe(['2026-09-15 11:00']);
});

it('blocks for every status that keeps the slot and frees it for the rest', function (LessonStatus $status) {
    $result = scSlots(['rules' => [scRule(2, '10:00', '11:00')], 'lessons' => [scLesson('2026-09-15 06:00:00', $status)]]);

    expect($result === [])->toBe($status->blocksSlot());
})->with(LessonStatus::cases());

it('excludes an active recurring slot on every matching date, even 10 weeks out', function () {
    $tuesdays = [scRule(2, '17:00', '18:00')];

    $without = scSlots(['rules' => $tuesdays, 'max' => 90]);
    $with = scSlots(['rules' => $tuesdays, 'max' => 90, 'recurring' => [scWeekly(2, '17:00', 'Asia/Dubai', '2026-09-14')]]);

    // 2026-09-15 + 70 days is Tuesday 2026-11-24.
    expect(scStarts($without, 'Asia/Dubai'))->toContain('2026-11-24 17:00')
        ->and($with)->toBe([]);
});

it('interprets a weekly slot in its own timezone: Tuesday 17:00 Karachi blocks Tuesday 16:00 Dubai', function () {
    $result = scSlots([
        'rules' => [scRule(2, '16:00', '18:00')],
        'recurring' => [scWeekly(2, '17:00', 'Asia/Karachi', '2026-09-14')],
    ]);

    // 17:00 Karachi = 12:00 UTC = 16:00 Dubai; only 17:00 Dubai survives.
    expect(scStarts($result, 'Asia/Dubai'))->toBe(['2026-09-15 17:00']);
});

it('blocks for a paused weekly slot: it keeps its place (R97, R99)', function () {
    $result = scSlots(['rules' => [scRule(2, '17:00', '18:00')], 'max' => 90, 'recurring' => [scWeekly(2, '17:00', 'Asia/Dubai', '2026-09-14', null, RecurringSlotStatus::Paused)]]);

    expect($result)->toBe([]);
});

it('does not block for an ended weekly slot', function () {
    $result = scSlots(['rules' => [scRule(2, '17:00', '18:00')], 'recurring' => [scWeekly(2, '17:00', 'Asia/Dubai', '2026-09-14', null, RecurringSlotStatus::Ended)]]);

    expect($result)->toHaveCount(1);
});

it('does not block before a weekly slot starts_on', function () {
    $result = scSlots([
        'rules' => [scRule(2, '17:00', '18:00')], 'max' => 21,
        'recurring' => [scWeekly(2, '17:00', 'Asia/Dubai', '2026-09-22')],
    ]);

    // 09-15 is before starts_on (offered); 09-22 and 09-29 are blocked.
    expect(scStarts($result, 'Asia/Dubai'))->toBe(['2026-09-15 17:00']);
});

it('stops blocking after a weekly slot ends_on', function () {
    $result = scSlots([
        'rules' => [scRule(2, '17:00', '18:00')], 'max' => 21,
        'recurring' => [scWeekly(2, '17:00', 'Asia/Dubai', '2026-09-14', '2026-09-22')],
    ]);

    expect(scStarts($result, 'Asia/Dubai'))->toBe(['2026-09-29 17:00']);
});

it('stops blocking after a tutor-ended slot end_effective_on, the date itself still blocked', function () {
    $result = scSlots([
        'rules' => [scRule(2, '17:00', '18:00')], 'max' => 21,
        'recurring' => [scWeekly(2, '17:00', 'Asia/Dubai', '2026-09-14', null, RecurringSlotStatus::Active, '2026-09-22')],
    ]);

    // 09-15 and 09-22 (the effective end date, inclusive) are blocked; 09-29 is offered.
    expect(scStarts($result, 'Asia/Dubai'))->toBe(['2026-09-29 17:00']);
});

it('ends at the earlier of ends_on and end_effective_on', function (?string $endsOn, ?string $endEffective, array $expected) {
    $result = scSlots([
        'rules' => [scRule(2, '17:00', '18:00')], 'max' => 28,
        'recurring' => [scWeekly(2, '17:00', 'Asia/Dubai', '2026-09-14', $endsOn, RecurringSlotStatus::Active, $endEffective)],
    ]);

    expect(scStarts($result, 'Asia/Dubai'))->toBe($expected);
})->with([
    'ends_on earlier' => ['2026-09-22', '2026-10-06', ['2026-09-29 17:00', '2026-10-06 17:00']],
    'end_effective_on earlier' => ['2026-10-06', '2026-09-22', ['2026-09-29 17:00', '2026-10-06 17:00']],
    'equal' => ['2026-09-22', '2026-09-22', ['2026-09-29 17:00', '2026-10-06 17:00']],
]);

it('respects booking_min_lead_hours at the boundary', function () {
    // Tuesday 09:00 Dubai = 2026-09-15 05:00 UTC; lead 12h.
    $r = [scRule(2, '09:00', '10:00')];

    expect(scSlots(['rules' => $r, 'now' => '2026-09-14 17:00:00']))->toHaveCount(1)   // exactly now + lead
        ->and(scSlots(['rules' => $r, 'now' => '2026-09-14 17:00:01']))->toBe([]);      // one second short
});

it('respects booking_max_days at the boundary', function () {
    $r = [scRule(2, '09:00', '10:00')]; // 2026-09-15 05:00 UTC

    expect(scSlots(['rules' => $r, 'now' => '2026-09-08 05:00:00', 'max' => 7]))->toHaveCount(1)  // exactly now + max
        ->and(scSlots(['rules' => $r, 'now' => '2026-09-08 04:59:59', 'max' => 7]))->toBe([]);
});

it('expands rules per local date across a DST change (Europe/London)', function () {
    // Saturday 10:00 London: BST (UTC+1) on 24 Oct 2026, GMT (UTC+0) on 31 Oct.
    $result = scSlots([
        'rules' => [scRule(6, '10:00', '11:00', 'Europe/London')],
        'now' => '2026-10-20 00:00:00', 'max' => 14, 'exceptionTz' => 'Europe/London', 'outTz' => 'UTC',
    ]);

    expect(scStarts($result))->toBe(['2026-10-24 09:00', '2026-10-31 10:00']);
});

it('sorts slots chronologically across several rules', function () {
    $result = scSlots(['rules' => [scRule(3, '09:00', '10:00'), scRule(2, '09:00', '10:00')]]);

    expect(scStarts($result, 'Asia/Dubai'))->toBe(['2026-09-15 09:00', '2026-09-16 09:00']);
});
