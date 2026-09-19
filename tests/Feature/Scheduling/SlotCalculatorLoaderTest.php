<?php

use App\Enums\AvailabilityExceptionType;
use App\Enums\LessonStatus;
use App\Enums\RecurringSlotStatus;
use App\Enums\TutorProfileStatus;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Lesson;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Services\Scheduling\SlotCalculator;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// 2026-09-14 06:00 UTC is a Monday; Tuesday is weekday 2 (Sunday = 0).
const SLOT_LOADER_NOW = '2026-09-14 06:00:00';

function slotLoaderNow(): CarbonImmutable
{
    return CarbonImmutable::parse(SLOT_LOADER_NOW, 'UTC');
}

function slotLoaderTutor(): TutorProfile
{
    $tutor = TutorProfile::factory()->approved()->create();
    AvailabilityRule::factory()->create([
        'tutor_profile_id' => $tutor->id, 'weekday' => 2, 'start_time' => '09:00:00', 'end_time' => '11:00:00', 'timezone' => 'Asia/Dubai',
    ]);
    Settings::set('booking_max_days', 7);

    return $tutor;
}

/**
 * @return list<string>
 */
function slotLoaderStarts(TutorProfile $tutor, ?string $tz = 'Asia/Dubai'): array
{
    return array_map(
        fn ($slot) => $slot->startsAt->format('Y-m-d H:i'),
        app(SlotCalculator::class)->forTutor($tutor->fresh(), $tz, slotLoaderNow()),
    );
}

it('loads rules, exceptions, lessons and weekly slots from the database', function () {
    $tutor = slotLoaderTutor();
    AvailabilityException::factory()->create([
        'tutor_profile_id' => $tutor->id, 'date' => '2026-09-16', 'start_time' => '14:00:00', 'end_time' => '15:00:00', 'type' => AvailabilityExceptionType::Extra,
    ]);

    expect(slotLoaderStarts($tutor))->toBe(['2026-09-15 09:00', '2026-09-15 10:00', '2026-09-16 14:00']);

    Lesson::factory()->startingAt(CarbonImmutable::parse('2026-09-15 05:00:00', 'UTC'))->create(['tutor_profile_id' => $tutor->id]);
    RecurringSlot::factory()->create(['tutor_profile_id' => $tutor->id, 'weekday' => 3, 'start_time' => '14:00:00', 'starts_on' => '2026-09-14']);

    // 09:00 Dubai lesson gone; the Wednesday extra hour is now a weekly slot.
    expect(slotLoaderStarts($tutor))->toBe(['2026-09-15 10:00']);
});

it('reflects an availability edit on the next calculation (R30 #1 — nothing is cached)', function () {
    $tutor = slotLoaderTutor();
    expect(slotLoaderStarts($tutor))->toHaveCount(2);

    AvailabilityRule::query()->where('tutor_profile_id', $tutor->id)->update(['end_time' => '10:00:00']);
    expect(slotLoaderStarts($tutor))->toBe(['2026-09-15 09:00']);

    AvailabilityException::factory()->create([
        'tutor_profile_id' => $tutor->id, 'date' => '2026-09-15', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'type' => AvailabilityExceptionType::Blocked,
    ]);
    expect(slotLoaderStarts($tutor))->toBe([]);
});

it('reads the lead and max-days settings at calculation time (R30 #2)', function () {
    $tutor = slotLoaderTutor(); // Tuesday 09:00/10:00 Dubai = 05:00/06:00 UTC

    expect(slotLoaderStarts($tutor))->toHaveCount(2);

    Settings::set('booking_min_lead_hours', 24); // window now starts Tue 06:00 UTC
    expect(slotLoaderStarts($tutor))->toBe(['2026-09-15 10:00']);

    Settings::set('booking_min_lead_hours', 12);
    Settings::set('booking_max_days', 0); // window ends Mon 06:00 UTC
    expect(slotLoaderStarts($tutor))->toBe([]);
});

it('offers slots of a tutor who is not bookable — callers apply bookable() themselves (R30 #3)', function () {
    $tutor = slotLoaderTutor();
    $tutor->forceFill(['status' => TutorProfileStatus::Suspended])->save();

    expect(slotLoaderStarts($tutor))->toHaveCount(2)
        ->and(TutorProfile::query()->bookable()->whereKey($tutor->id)->exists())->toBeFalse();
});

it('stops a paused or ended weekly slot blocking while its reserved lesson still does (R30 #4)', function () {
    $tutor = slotLoaderTutor();
    $slot = RecurringSlot::factory()->create(['tutor_profile_id' => $tutor->id, 'weekday' => 2, 'start_time' => '09:00:00', 'starts_on' => '2026-09-14']);

    expect(slotLoaderStarts($tutor))->toBe(['2026-09-15 10:00']);

    $slot->forceFill(['status' => RecurringSlotStatus::Paused])->save();
    expect(slotLoaderStarts($tutor))->toHaveCount(2);

    // A lesson generated from the slot (CP4) is a lesson: it blocks on its own.
    Lesson::factory()->startingAt(CarbonImmutable::parse('2026-09-15 05:00:00', 'UTC'))->create(['tutor_profile_id' => $tutor->id, 'status' => LessonStatus::Reserved]);
    expect(slotLoaderStarts($tutor))->toBe(['2026-09-15 10:00']);
});

it('reopens a slot when its lesson is cancelled (R30 #5)', function () {
    $tutor = slotLoaderTutor();
    $lesson = Lesson::factory()->startingAt(CarbonImmutable::parse('2026-09-15 05:00:00', 'UTC'))->create(['tutor_profile_id' => $tutor->id]);
    expect(slotLoaderStarts($tutor))->toBe(['2026-09-15 10:00']);

    // Factories set status at creation; this direct write stands in for CP3's state machine.
    Lesson::query()->whereKey($lesson->id)->update(['status' => LessonStatus::CancelledByParent->value]);
    expect(slotLoaderStarts($tutor))->toHaveCount(2);
});

it('reads exceptions in the tutor’s current timezone while rules keep their own (R30 #8)', function () {
    $tutor = slotLoaderTutor();
    AvailabilityException::factory()->create([
        'tutor_profile_id' => $tutor->id, 'date' => '2026-09-16', 'start_time' => '14:00:00', 'end_time' => '15:00:00', 'type' => AvailabilityExceptionType::Extra,
    ]);

    expect(slotLoaderStarts($tutor, 'UTC'))->toBe(['2026-09-15 05:00', '2026-09-15 06:00', '2026-09-16 10:00']);

    $tutor->user->forceFill(['timezone' => 'Asia/Karachi'])->save();

    // Rules unchanged (their own zone); the exception moved with the new user timezone.
    expect(slotLoaderStarts($tutor, 'UTC'))->toBe(['2026-09-15 05:00', '2026-09-15 06:00', '2026-09-16 09:00']);
});

it('does not double-book a tutor start time in the database, but a cancelled lesson frees it', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $start = CarbonImmutable::parse('2026-09-15 05:00:00', 'UTC');
    $first = Lesson::factory()->startingAt($start)->create(['tutor_profile_id' => $tutor->id]);

    expect(fn () => DB::transaction(fn () => Lesson::factory()->startingAt($start)->create(['tutor_profile_id' => $tutor->id, 'status' => LessonStatus::PendingPayment])))
        ->toThrow(QueryException::class);

    Lesson::query()->whereKey($first->id)->update(['status' => LessonStatus::Expired->value]);
    expect(Lesson::factory()->startingAt($start)->create(['tutor_profile_id' => $tutor->id])->exists)->toBeTrue();
});

it('allows only one active weekly slot per tutor weekday and time', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    RecurringSlot::factory()->create(['tutor_profile_id' => $tutor->id]);

    expect(fn () => DB::transaction(fn () => RecurringSlot::factory()->create(['tutor_profile_id' => $tutor->id])))->toThrow(QueryException::class);

    RecurringSlot::factory()->create(['tutor_profile_id' => $tutor->id, 'status' => RecurringSlotStatus::Ended]); // ended rows do not collide
    expect(RecurringSlot::query()->count())->toBe(2);
});
