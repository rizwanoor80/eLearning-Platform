<?php

use App\Enums\AvailabilityExceptionType;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Scheduling\Slot;
use App\Services\Scheduling\SlotCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function batchTutor(string $timezone): TutorProfile
{
    $user = User::factory()->tutor()->create(['timezone' => $timezone]);
    $tutor = TutorProfile::factory()->approved()->create(['user_id' => $user->id]);
    AvailabilityRule::factory()->create(['tutor_profile_id' => $tutor->id, 'weekday' => 2, 'start_time' => '09:00:00', 'end_time' => '11:00:00', 'timezone' => $timezone]);
    // No timezone column on exceptions: each tutor's own user timezone must apply.
    AvailabilityException::factory()->create(['tutor_profile_id' => $tutor->id, 'date' => '2026-09-16', 'start_time' => '14:00:00', 'end_time' => '15:00:00', 'type' => AvailabilityExceptionType::Extra]);
    Lesson::factory()->startingAt(CarbonImmutable::parse('2026-09-15 05:00:00', 'UTC'))->create(['tutor_profile_id' => $tutor->id]);

    return $tutor;
}

/**
 * @param  list<Slot>  $slots
 * @return list<string>
 */
function batchStarts(array $slots): array
{
    return array_map(fn ($slot) => $slot->startsAt->format('Y-m-d H:i'), $slots);
}

it('gives the batch loader the same answer as the single loader for every tutor (R30 #6, #14)', function () {
    $now = CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC');
    $dubai = batchTutor('Asia/Dubai');
    $karachi = batchTutor('Asia/Karachi');
    $calculator = app(SlotCalculator::class);

    $batch = $calculator->forTutors([$dubai, $karachi], 'UTC', $now, 14);

    expect(array_keys($batch))->toEqualCanonicalizing([$dubai->id, $karachi->id]);

    foreach ([$dubai, $karachi] as $tutor) {
        expect(batchStarts($batch[$tutor->id]))->toBe(batchStarts($calculator->forTutor($tutor, 'UTC', $now, 14)));
    }

    // The 14:00 Wednesday extra hour follows each tutor's own timezone.
    expect(batchStarts($batch[$dubai->id]))->toContain('2026-09-16 10:00')->not->toContain('2026-09-16 09:00')
        ->and(batchStarts($batch[$karachi->id]))->toContain('2026-09-16 09:00')->not->toContain('2026-09-16 10:00');
});

it('runs one query per input table however many tutors are batched (R30 #6)', function () {
    $now = CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC');
    $tutors = collect(range(1, 8))->map(fn () => batchTutor('Asia/Dubai'));
    $calculator = app(SlotCalculator::class);
    $calculator->forTutors($tutors->take(1)->all(), 'UTC', $now, 14); // warm the settings cache

    $count = function (array $subset) use ($calculator, $now): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $calculator->forTutors($subset, 'UTC', $now, 14);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $eager = TutorProfile::query()->with('user:id,timezone')->get();

    expect($count($eager->take(2)->all()))->toBe(4)
        ->and($count($eager->all()))->toBe(4);
});

it('returns nothing for no tutors', function () {
    expect(app(SlotCalculator::class)->forTutors([], 'UTC'))->toBe([]);
});

it('leaves the caller’s models untouched and costs one extra query for tutors without a loaded user (R30 #8)', function () {
    $now = CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC');
    $tutors = collect(range(1, 3))->map(fn () => batchTutor('Asia/Dubai'));
    $calculator = app(SlotCalculator::class);
    $calculator->forTutors($tutors->take(1)->all(), 'UTC', $now, 14); // warm the settings cache

    $bare = TutorProfile::query()->get();
    expect($bare->first()->relationLoaded('user'))->toBeFalse();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $batch = $calculator->forTutors($bare->all(), 'UTC', $now, 14);
    DB::disableQueryLog();

    expect(count(DB::getQueryLog()))->toBe(5)                       // four inputs + one timezone lookup
        ->and($bare->first()->relationLoaded('user'))->toBeFalse()  // the caller's model was not given a relation
        ->and(array_keys($batch))->toEqualCanonicalizing($bare->pluck('id')->all());
});
