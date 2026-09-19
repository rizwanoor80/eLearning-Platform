<?php

use App\Models\AvailabilityRule;
use App\Models\TutorProfile;
use App\Services\Scheduling\SlotCalculator;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;

// R34 — slots are never offered on or after the permit's expiry day (UTC midnight, exclusive).
// Clock: Monday 2026-09-14 06:00 UTC; Tuesday is weekday 2.
beforeEach(fn () => test()->travelTo(CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC')));

/**
 * A tutor with a weekly rule on every day 09:00–11:00 Dubai (05:00 and 06:00 UTC).
 */
function capTutor(string $permitExpiresOn): TutorProfile
{
    $tutor = TutorProfile::factory()->approved()->create(['permit_expires_at' => $permitExpiresOn]);

    foreach (range(0, 6) as $weekday) {
        AvailabilityRule::factory()->create(['tutor_profile_id' => $tutor->id, 'weekday' => $weekday, 'start_time' => '09:00:00', 'end_time' => '11:00:00', 'timezone' => 'Asia/Dubai']);
    }

    return $tutor;
}

/**
 * @return list<string>
 */
function capStarts(TutorProfile $tutor): array
{
    return array_map(fn ($slot) => $slot->startsAt->format('Y-m-d H:i'), app(SlotCalculator::class)->forTutor($tutor->fresh(), 'UTC', null, 14));
}

it('offers no slot on or after the expiry day, and the last slot before it (R30 #2)', function () {
    $tutor = capTutor('2026-09-19'); // five days out

    $starts = capStarts($tutor);

    expect($starts)->toContain('2026-09-18 06:00')           // the last slot before the expiry day's midnight
        ->and($starts)->not->toContain('2026-09-19 05:00')   // first slot on the expiry day
        ->and(collect($starts)->filter(fn ($s) => $s >= '2026-09-19')->all())->toBe([])
        ->and(end($starts))->toBe('2026-09-18 06:00');
});

it('still offers slots up to the 14-day window when the permit outlasts it', function () {
    $starts = capStarts(capTutor('2026-12-31'));

    expect(end($starts))->toBe('2026-09-28 06:00'); // now + 14 days, at 06:00 UTC
});

it('offers nothing when the permit expires before the window even starts (R30 #2)', function () {
    // lead 12 h → the window opens Mon 18:00 UTC; the permit expires today at midnight.
    expect(capStarts(capTutor('2026-09-14')))->toBe([]);
});

it('does not cap a tutor with no permit date (R30 #4)', function () {
    $tutor = capTutor('2026-12-31');
    $tutor->forceFill(['permit_expires_at' => null])->save();

    $starts = capStarts($tutor);

    expect($starts)->not->toBe([])->and(end($starts))->toBe('2026-09-28 06:00');
});

it('offers the slots again on the next calculation once the permit is renewed (R30 #3)', function () {
    $tutor = capTutor('2026-09-17');
    expect(collect(capStarts($tutor))->filter(fn ($s) => $s >= '2026-09-17')->all())->toBe([]);

    $tutor->forceFill(['permit_expires_at' => '2026-12-31'])->save();

    expect(capStarts($tutor))->toContain('2026-09-20 05:00');
});

it('takes a tutor out of search when their only slots fall after the permit expires (R30 #13)', function () {
    // Availability on Saturday (weekday 6) only: the first such slots are on day 5 (2026-09-19).
    $short = TutorProfile::factory()->approved()->create(['permit_expires_at' => '2026-09-17']);   // expires in 3 days
    $long = TutorProfile::factory()->approved()->create(['permit_expires_at' => '2026-09-24']);    // expires in 10 days
    foreach ([$short, $long] as $tutor) {
        AvailabilityRule::factory()->create(['tutor_profile_id' => $tutor->id, 'weekday' => 6, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'timezone' => 'Asia/Dubai']);
    }

    $ids = [];
    test()->get(route('tutors.index'))->assertOk()->assertInertia(function ($page) use (&$ids) {
        $ids = collect($page->toArray()['props']['tutors'])->pluck('id')->all();
    });

    expect($ids)->toBe([$long->id])->not->toContain($short->id);

    // Still bookable, so the profile exists — it just has nothing to offer.
    test()->get(route('tutors.show', $short->id))->assertOk()->assertInertia(fn ($page) => $page->has('tutor.next_slots', 0));
});

it('never calculates a horizon beyond 90 days, whatever the stored setting says (R30 #5)', function () {
    Settings::set('booking_max_days', 365);
    $tutor = capTutor('2030-01-01');

    $starts = array_map(fn ($slot) => $slot->startsAt->format('Y-m-d'), app(SlotCalculator::class)->forTutor($tutor->fresh(), 'UTC'));

    expect(end($starts))->toBe('2026-12-13')  // 2026-09-14 06:00 + 90 days
        ->and(SlotCalculator::MAX_HORIZON_DAYS)->toBe(90);
});

it('refuses a slot at exactly 00:00 UTC on the expiry day and offers 23:00 UTC the day before (step-1 review L2)', function () {
    // 04:00 Dubai is 00:00 UTC: with a rule 04:00–05:00 every day, the slot at exactly the cutoff instant.
    $tutor = TutorProfile::factory()->approved()->create(['permit_expires_at' => '2026-09-19']);
    foreach (range(0, 6) as $weekday) {
        AvailabilityRule::factory()->create(['tutor_profile_id' => $tutor->id, 'weekday' => $weekday, 'start_time' => '03:00:00', 'end_time' => '05:00:00', 'timezone' => 'Asia/Dubai']);
    }

    $starts = capStarts($tutor);

    // Dubai 03:00 = 23:00 UTC the day before; Dubai 04:00 = 00:00 UTC. The cutoff is 2026-09-19 00:00 UTC (exclusive).
    expect($starts)->toContain('2026-09-18 23:00')
        ->and($starts)->not->toContain('2026-09-19 00:00')
        ->and(end($starts))->toBe('2026-09-18 23:00');
});
