<?php

use App\Enums\AvailabilityExceptionType;
use App\Enums\LessonStatus;
use App\Enums\TutorProfileStatus;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;

// Fixed clock: Monday 2026-09-14 06:00 UTC. Tuesday is weekday 2 (Sunday = 0).
beforeEach(fn () => test()->travelTo(CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC')));

/**
 * A tutor with REAL availability (a Tuesday rule inside the 14-day window) and
 * one subject row — so a non-bookable one can only be missing because the
 * bookable() scope removed it, not because it had nothing to offer.
 *
 * @param  array<string, mixed>  $profile
 * @param  array<string, mixed>  $subject
 */
function srchTutor(array $profile = [], array $subject = [], bool $withRule = true, string $name = 'Layla Hassan'): TutorProfile
{
    $user = User::factory()->tutor()->create(['name' => $name, 'timezone' => 'Asia/Dubai']);
    $tutor = TutorProfile::factory()->approved()->create(array_merge(['user_id' => $user->id, 'hourly_rate' => 10000], $profile));

    TutorSubject::factory()->create(array_merge(['tutor_profile_id' => $tutor->id, 'curriculum_id' => (Curriculum::query()->first() ?? Curriculum::factory()->create())->id], $subject));

    if ($withRule) {
        AvailabilityRule::factory()->create([
            'tutor_profile_id' => $tutor->id, 'weekday' => 2, 'start_time' => '09:00:00', 'end_time' => '11:00:00', 'timezone' => 'Asia/Dubai',
        ]);
    }

    return $tutor;
}

/**
 * @return list<int>
 */
function srchIds(?User $viewer = null, array $query = []): array
{
    $request = $viewer ? test()->actingAs($viewer) : test();
    $ids = [];
    $request->get(route('tutors.index', $query))->assertOk()
        ->assertInertia(function ($page) use (&$ids) {
            $ids = collect($page->toArray()['props']['tutors'])->pluck('id')->all();
        });

    return $ids;
}

// ---- R30 #1 / box 1: never a non-bookable tutor --------------------------------------------

it('never lists a tutor who is not bookable, even with real availability (R30 #1)', function (array $profile) {
    $twin = srchTutor();
    $hidden = srchTutor($profile);

    expect(srchIds())->toBe([$twin->id])->not->toContain($hidden->id);
})->with([
    'suspended' => [['status' => TutorProfileStatus::Suspended]],
    'pending review' => [['status' => TutorProfileStatus::PendingReview]],
    'draft' => [['status' => TutorProfileStatus::Draft]],
    'rejected' => [['status' => TutorProfileStatus::Rejected]],
    'changes requested' => [['status' => TutorProfileStatus::ChangesRequested]],
    'expired permit' => [['permit_expires_at' => '2026-09-01']],
    'permit expiring today' => [['permit_expires_at' => '2026-09-14']],
]);

it('lists a tutor with no availability nowhere', function () {
    $twin = srchTutor();
    srchTutor(withRule: false);

    expect(srchIds())->toBe([$twin->id]);
});

it('reflects a suspension on the very next search and profile request (R30 #2)', function () {
    $tutor = srchTutor();
    expect(srchIds())->toBe([$tutor->id]);
    test()->get(route('tutors.show', $tutor->id))->assertOk();

    $tutor->forceFill(['status' => TutorProfileStatus::Suspended])->save();

    expect(srchIds())->toBe([]);
    test()->get(route('tutors.show', $tutor->id))->assertNotFound();
});

it('reflects a rate or subject edit on the next search (R30 #3)', function () {
    $tutor = srchTutor(['hourly_rate' => 10000]);
    expect(srchIds(query: ['max_price' => '90']))->toBe([]);

    $tutor->forceFill(['hourly_rate' => 8000])->save();
    expect(srchIds(query: ['max_price' => '90']))->toBe([$tutor->id]);

    $subject = Subject::factory()->create();
    expect(srchIds(query: ['subject_id' => $subject->id]))->toBe([]);
    TutorSubject::factory()->create(['tutor_profile_id' => $tutor->id, 'subject_id' => $subject->id]);
    expect(srchIds(query: ['subject_id' => $subject->id]))->toBe([$tutor->id]);
});

// ---- R30 #4 #5: the 14-day slot rule --------------------------------------------------------

it('drops a tutor whose only slot gets booked or blocked (R30 #4)', function () {
    $tutor = srchTutor(withRule: false);
    AvailabilityException::factory()->create([
        'tutor_profile_id' => $tutor->id, 'date' => '2026-09-16', 'start_time' => '14:00:00', 'end_time' => '15:00:00', 'type' => AvailabilityExceptionType::Extra,
    ]);
    expect(srchIds())->toBe([$tutor->id]);

    $lesson = Lesson::factory()->startingAt(CarbonImmutable::parse('2026-09-16 10:00:00', 'UTC'))->create(['tutor_profile_id' => $tutor->id]);
    expect(srchIds())->toBe([]);

    Lesson::query()->whereKey($lesson->id)->update(['status' => LessonStatus::CancelledByParent->value]);
    expect(srchIds())->toBe([$tutor->id]);

    AvailabilityException::factory()->create([
        'tutor_profile_id' => $tutor->id, 'date' => '2026-09-16', 'start_time' => '14:00:00', 'end_time' => '15:00:00', 'type' => AvailabilityExceptionType::Blocked,
    ]);
    expect(srchIds())->toBe([]);
});

it('only counts slots in the next 14 days, narrowed by booking_max_days (R30 #5)', function () {
    $near = srchTutor(withRule: false, name: 'Near Tutor');   // slot on day +10
    $far = srchTutor(withRule: false, name: 'Far Tutor');     // slot on day +16
    foreach ([[$near, '2026-09-24'], [$far, '2026-09-30']] as [$tutor, $date]) {
        AvailabilityException::factory()->create([
            'tutor_profile_id' => $tutor->id, 'date' => $date, 'start_time' => '14:00:00', 'end_time' => '15:00:00', 'type' => AvailabilityExceptionType::Extra,
        ]);
    }

    expect(srchIds())->toBe([$near->id]);   // default max_days 30 → window 14

    Settings::set('booking_max_days', 7);
    expect(srchIds())->toBe([]);            // window is min(7, 14)
});

// ---- filters and sorts ----------------------------------------------------------------------

it('filters by curriculum and subject', function () {
    $gcse = Curriculum::factory()->create();
    $cbse = Curriculum::factory()->create();
    $maths = Subject::factory()->create();
    $a = srchTutor(subject: ['curriculum_id' => $gcse->id, 'subject_id' => $maths->id]);
    $b = srchTutor(subject: ['curriculum_id' => $cbse->id]);

    expect(srchIds(query: ['curriculum_id' => $gcse->id]))->toBe([$a->id])
        ->and(srchIds(query: ['curriculum_id' => $cbse->id]))->toBe([$b->id])
        ->and(srchIds(query: ['subject_id' => $maths->id]))->toBe([$a->id])
        ->and(srchIds(query: ['curriculum_id' => $cbse->id, 'subject_id' => $maths->id]))->toBe([]);
});

it('filters by a price range and sorts by price or rating', function () {
    $cheap = srchTutor(['hourly_rate' => 8000, 'rating_avg' => 4.0, 'rating_count' => 3]);
    $mid = srchTutor(['hourly_rate' => 12000, 'rating_avg' => 4.9, 'rating_count' => 10]);
    $dear = srchTutor(['hourly_rate' => 20000, 'rating_avg' => 4.5, 'rating_count' => 5]);

    expect(srchIds(query: ['min_price' => '100', 'max_price' => '150']))->toBe([$mid->id])
        ->and(srchIds(query: ['sort' => 'price']))->toBe([$cheap->id, $mid->id, $dear->id])
        ->and(srchIds(query: ['sort' => 'rating']))->toBe([$mid->id, $dear->id, $cheap->id]);
});

it('excludes unrated tutors once a minimum rating is set (R30 #10)', function () {
    $unrated = srchTutor();
    $rated = srchTutor(['rating_avg' => 4.6, 'rating_count' => 8]);

    expect(srchIds())->toContain($unrated->id, $rated->id)
        ->and(srchIds(query: ['min_rating' => '4']))->toBe([$rated->id])
        ->and(srchIds(query: ['min_rating' => '4.8']))->toBe([])
        ->and(srchIds(query: ['min_rating' => '0']))->toBe([$rated->id]);
});

it('filters by day and time of day in the viewer timezone (R30 #8)', function () {
    $tutor = srchTutor(); // Tuesday 09:00 and 10:00 Dubai = 05:00 / 06:00 UTC

    // Dubai viewer (default): Tuesday morning.
    expect(srchIds(query: ['day' => 2, 'time_of_day' => 'morning']))->toBe([$tutor->id])
        ->and(srchIds(query: ['day' => 2, 'time_of_day' => 'evening']))->toBe([])
        ->and(srchIds(query: ['day' => 1]))->toBe([]);

    // A viewer in Pago Pago (UTC−11) sees the same slots on Monday evening.
    $viewer = User::factory()->create(['timezone' => 'Pacific/Pago_Pago']);
    expect(srchIds($viewer, ['day' => 1, 'time_of_day' => 'evening']))->toBe([$tutor->id])
        ->and(srchIds($viewer, ['day' => 2]))->toBe([]);
});

it('shifts guest results with the default_timezone setting (R30 #13)', function () {
    $tutor = srchTutor();
    expect(srchIds(query: ['day' => 2]))->toBe([$tutor->id]);

    Settings::set('default_timezone', 'Pacific/Pago_Pago');
    expect(srchIds(query: ['day' => 2]))->toBe([])->and(srchIds(query: ['day' => 1]))->toBe([$tutor->id]);
});

// ---- year group (R30 #11) -------------------------------------------------------------------

it('filters by year group within the chosen curriculum, permissive when unparseable (R30 #11)', function () {
    $curriculum = Curriculum::factory()->create();
    $other = Curriculum::factory()->create();
    $inRange = srchTutor(subject: ['curriculum_id' => $curriculum->id, 'level_min' => 'Year 7', 'level_max' => 'Year 9']);
    $outOfRange = srchTutor(subject: ['curriculum_id' => $curriculum->id, 'level_min' => 'Year 10', 'level_max' => 'Year 11']);
    $unparseable = srchTutor(subject: ['curriculum_id' => $curriculum->id, 'level_min' => 'MYP One', 'level_max' => 'MYP Three']);
    $wrongCurriculum = srchTutor(subject: ['curriculum_id' => $other->id, 'level_min' => 'Year 7', 'level_max' => 'Year 9']);

    $ids = srchIds(query: ['curriculum_id' => $curriculum->id, 'year_group' => 'Year 8']);
    expect($ids)->toContain($inRange->id, $unparseable->id)->not->toContain($outOfRange->id, $wrongCurriculum->id);

    expect(srchIds(query: ['curriculum_id' => $curriculum->id, 'year_group' => 'Year 10']))->toContain($outOfRange->id)->not->toContain($inRange->id)
        ->and(srchIds(query: ['curriculum_id' => $curriculum->id, 'year_group' => 'Reception']))->toContain($inRange->id, $outOfRange->id) // no integer → permissive
        ->and(srchIds(query: ['year_group' => 'Year 10']))->toContain($inRange->id); // no curriculum → year ignored
});

// ---- learner prefill (R30 #7, #15) ----------------------------------------------------------

it('prefills curriculum and year group from the owner’s own learner', function () {
    $curriculum = Curriculum::factory()->create();
    $match = srchTutor(subject: ['curriculum_id' => $curriculum->id, 'level_min' => 'Year 7', 'level_max' => 'Year 9']);
    $miss = srchTutor(subject: ['curriculum_id' => Curriculum::factory()->create()->id, 'level_min' => 'Year 7', 'level_max' => 'Year 9']);
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => $curriculum->id, 'year_group' => 'Year 8']);

    expect(srchIds($parent, ['learner' => $learner->id]))->toBe([$match->id])->not->toContain($miss->id);
});

it('ignores a learner with no curriculum, a foreign, a soft-deleted or any learner for a guest (R30 #7, #15)', function () {
    $a = srchTutor();
    $b = srchTutor();
    $parent = User::factory()->create();
    $adult = Learner::factory()->selfLearner()->create(['account_user_id' => $parent->id]);
    $stranger = User::factory()->create();
    $foreign = Learner::factory()->create(['account_user_id' => $stranger->id]);
    $gone = Learner::factory()->create(['account_user_id' => $parent->id]);
    $gone->delete();

    expect(srchIds($parent, ['learner' => $adult->id]))->toBe([$a->id, $b->id])   // null curriculum → no prefill, no error
        ->and(srchIds($parent, ['learner' => $foreign->id]))->toBe([$a->id, $b->id])
        ->and(srchIds($parent, ['learner' => $gone->id]))->toBe([$a->id, $b->id])
        ->and(srchIds(query: ['learner' => $foreign->id]))->toBe([$a->id, $b->id]);

    test()->actingAs($parent)->get(route('tutors.index', ['learner' => $foreign->id]))
        ->assertInertia(fn ($page) => $page->where('filters.learner', null)->where('filters.curriculum_id', null));
});

// ---- public-page leak tests -----------------------------------------------------------------

it('exposes exactly the allow-listed keys on each search row and on the profile', function () {
    $tutor = srchTutor();

    test()->get(route('tutors.index'))->assertInertia(function ($page) {
        $row = $page->toArray()['props']['tutors'][0];
        expect(array_keys($row))->toEqualCanonicalizing(['id', 'name', 'headline', 'rate', 'trial_price', 'rating_avg', 'rating_count', 'subjects', 'next_slot']);
        expect(array_keys($row['subjects'][0]))->toEqualCanonicalizing(['curriculum', 'subject', 'level_min', 'level_max']);
    });

    test()->get(route('tutors.show', $tutor->id))->assertInertia(function ($page) {
        $tutorProps = $page->toArray()['props']['tutor'];
        expect(array_keys($tutorProps))->toEqualCanonicalizing(['id', 'name', 'headline', 'rate', 'trial_price', 'rating_avg', 'rating_count', 'bio', 'intro_video_url', 'subjects', 'next_slots', 'reviews']);
    });
});

it('never leaks private tutor data into the rendered page', function () {
    $tutor = srchTutor(['permit_number' => 'PMT-SECRET-123', 'bank_iban' => 'AE070331234567890123456', 'review_note' => 'internal note'], name: 'Layla Hassan');
    $tutor->user->forceFill(['email' => 'layla.private@example.test', 'phone' => '+971500000000'])->save();

    foreach ([route('tutors.index'), route('tutors.show', $tutor->id)] as $url) {
        $html = test()->get($url)->getContent();
        expect($html)->not->toContain('PMT-SECRET-123')
            ->not->toContain('AE070331234567890123456')
            ->not->toContain('layla.private@example.test')
            ->not->toContain('+971500000000')
            ->not->toContain('internal note')
            ->not->toContain('Hassan');
    }
});

it('shows a first name only and an outbound link only for an http(s) intro video', function () {
    $ok = srchTutor(['intro_video_url' => 'https://video.example/watch?v=1']);
    $bad = srchTutor(['intro_video_url' => 'javascript:alert(1)']);

    test()->get(route('tutors.show', $ok->id))->assertInertia(fn ($page) => $page->where('tutor.name', 'Layla')->where('tutor.intro_video_url', 'https://video.example/watch?v=1'));
    test()->get(route('tutors.show', $bad->id))->assertInertia(fn ($page) => $page->where('tutor.intro_video_url', null));
});

// ---- profile page ---------------------------------------------------------------------------

it('404s a profile that is not bookable or does not exist, with real availability on the hidden ones', function (array $profile) {
    $hidden = srchTutor($profile);

    test()->get(route('tutors.show', $hidden->id))->assertNotFound();
    test()->get(route('tutors.show', 999999))->assertNotFound();
})->with([
    'suspended' => [['status' => TutorProfileStatus::Suspended]],
    'draft' => [['status' => TutorProfileStatus::Draft]],
    'expired permit' => [['permit_expires_at' => '2026-09-01']],
    'expiring today' => [['permit_expires_at' => '2026-09-14']],
]);

it('shows the profile with the rate, the derived trial price and next slots in the viewer timezone', function () {
    $tutor = srchTutor(['hourly_rate' => 12000]);

    test()->get(route('tutors.show', $tutor->id))->assertOk()->assertInertia(fn ($page) => $page
        ->component('tutors/Show')
        ->where('tutor.rate', 'AED 120.00')
        ->where('tutor.trial_price', 'AED 60.00')
        ->where('tutor.next_slots.0.label', 'Tue 15 Sep, 09:00')
        ->where('timezone', 'Asia/Dubai'));

    $london = User::factory()->create(['timezone' => 'Europe/London']);
    test()->actingAs($london)->get(route('tutors.show', $tutor->id))
        ->assertInertia(fn ($page) => $page->where('tutor.next_slots.0.label', 'Tue 15 Sep, 06:00')->where('timezone', 'Europe/London'));
});

it('re-derives the trial price when trial_discount_pct changes (R30 #12)', function () {
    $tutor = srchTutor(['hourly_rate' => 10000]);
    Settings::set('trial_discount_pct', 30);

    test()->get(route('tutors.show', $tutor->id))->assertInertia(fn ($page) => $page->where('tutor.trial_price', 'AED 70.00'));

    expect($tutor->fresh()->trialPrice()->toFils())->toBe(7000);
});

it('paginates the results', function () {
    foreach (range(1, 13) as $i) {
        srchTutor();
    }

    test()->get(route('tutors.index'))->assertInertia(fn ($page) => $page->has('tutors', 12)->where('total', 13)->where('lastPage', 2)->where('page', 1));
    test()->get(route('tutors.index', ['page' => 2]))->assertInertia(fn ($page) => $page->has('tutors', 1)->where('page', 2));
    test()->get(route('tutors.index', ['page' => 99]))->assertInertia(fn ($page) => $page->where('page', 2));
});

it('rejects malformed filters', function (array $query, string $field) {
    test()->get(route('tutors.index', $query))->assertSessionHasErrors($field);
})->with([
    'price with letters' => [['min_price' => 'abc'], 'min_price'],
    'price with three decimals' => [['max_price' => '10.123'], 'max_price'],
    'day out of range' => [['day' => 9], 'day'],
    'rating above five' => [['min_rating' => 6], 'min_rating'],
    'unknown sort' => [['sort' => 'random'], 'sort'],
    'unknown time of day' => [['time_of_day' => 'midnight'], 'time_of_day'],
]);
