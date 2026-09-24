<?php

use App\Enums\LessonStatus;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

/**
 * PLAN.md step 5 tutor dashboard slice: today/upcoming buckets split on the
 * tutor's own local-day boundary (not UTC midnight, not the server's zone),
 * mirroring SlotCalculator's local-day idiom.
 */
function tdLesson(TutorProfile $tutor, LessonStatus $status, DateTimeInterface $startsAtUtc): Lesson
{
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => gcseCurriculumId()]);

    return Lesson::factory()->withStatus($status)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => $startsAtUtc,
        'ends_at' => Carbon::instance($startsAtUtc)->addHour(),
    ]);
}

it('renders empty today/upcoming lists for a tutor with no profile row, without creating one', function () {
    $tutor = User::factory()->tutor()->create();

    test()->actingAs($tutor)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->component('tutor/Dashboard')
            ->has('today', 0)
            ->has('upcoming', 0));

    expect($tutor->tutorProfile()->exists())->toBeFalse();
});

it('buckets lessons by the tutor\'s own local day, not UTC midnight or the server zone', function () {
    // Pacific/Kiritimati is UTC+14: local midnight on 11 Jan falls on 10 Jan 10:00 UTC.
    Carbon::setTestNow(Carbon::parse('2026-01-11 05:00:00', 'UTC')); // 2026-01-11 19:00 local

    $tutorUser = User::factory()->tutor()->create(['timezone' => 'Pacific/Kiritimati']);
    $tutor = TutorProfile::factory()->approved()->create(['user_id' => $tutorUser->id]);

    // 2026-01-11 20:00 local (still "today" locally, even though it's already 2026-01-12 06:00 UTC).
    $todayLesson = tdLesson($tutor, LessonStatus::Confirmed, Carbon::parse('2026-01-11 06:00:00', 'UTC'));
    // 2026-01-12 08:00 local — tomorrow locally, so "upcoming".
    $upcomingLesson = tdLesson($tutor, LessonStatus::Confirmed, Carbon::parse('2026-01-11 18:00:00', 'UTC'));

    test()->actingAs($tutor->user)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('today', 1)
            ->where('today.0.id', $todayLesson->id)
            ->where('today.0.starts_at', 'Sun, 11 Jan 2026, 8:00 PM')
            ->has('upcoming', 1)
            ->where('upcoming.0.id', $upcomingLesson->id)
            ->where('upcoming.0.starts_at', 'Mon, 12 Jan 2026, 8:00 AM'));
});

it('shows an in-progress lesson only in today, never in upcoming', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-11 12:00:00', 'Asia/Dubai'));

    $tutor = TutorProfile::factory()->approved()->create();
    $lesson = tdLesson($tutor, LessonStatus::InProgress, now()->copy());

    test()->actingAs($tutor->user)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->has('today', 1)
            ->where('today.0.id', $lesson->id)
            ->has('upcoming', 0));
});

it('excludes a pending-payment lesson from both today and upcoming', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-11 12:00:00', 'Asia/Dubai'));

    $tutor = TutorProfile::factory()->approved()->create();
    tdLesson($tutor, LessonStatus::PendingPayment, now()->copy());
    tdLesson($tutor, LessonStatus::PendingPayment, now()->addDay());

    test()->actingAs($tutor->user)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->has('today', 0)->has('upcoming', 0));
});

it('does not show another tutor\'s lessons', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-11 12:00:00', 'Asia/Dubai'));

    $tutor = TutorProfile::factory()->approved()->create();
    tdLesson(TutorProfile::factory()->approved()->create(), LessonStatus::Confirmed, now()->copy());

    test()->actingAs($tutor->user)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->has('today', 0)->has('upcoming', 0));
});
