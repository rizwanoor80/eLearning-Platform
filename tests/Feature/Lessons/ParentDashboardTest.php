<?php

use App\Enums\LessonStatus;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

/**
 * PLAN.md step 5 parent dashboard slice: only the authenticated parent's own
 * bookable-status lessons, correctly timezone-formatted, with the right
 * cancel_kind per status.
 */

/**
 * @return array{lesson: Lesson, parent: User, tutor: TutorProfile}
 */
function pdSetup(LessonStatus $status = LessonStatus::Confirmed, array $overrides = []): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => gcseCurriculumId()]);

    $lesson = Lesson::factory()->withStatus($status)->create(array_merge([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
    ], $overrides));

    return ['lesson' => $lesson->fresh(), 'parent' => $parent, 'tutor' => $tutor];
}

it('lists only the authenticated parent\'s own lessons, not another parent\'s', function () {
    ['lesson' => $mine, 'parent' => $parent] = pdSetup();
    pdSetup(); // a second parent's lesson

    test()->actingAs($parent)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->component('Dashboard')
            ->has('upcoming', 1)
            ->where('upcoming.0.id', $mine->id));
});

it('excludes a pending-payment lesson from the dashboard', function () {
    ['parent' => $parent] = pdSetup(LessonStatus::PendingPayment);

    test()->actingAs($parent)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('upcoming', 0));
});

it('excludes a cancelled lesson from the dashboard', function () {
    ['parent' => $parent] = pdSetup(LessonStatus::Refunded);

    test()->actingAs($parent)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('upcoming', 0));
});

it('marks a reserved lesson skip-able and a confirmed lesson cancel-able', function () {
    ['lesson' => $reserved, 'parent' => $parent] = pdSetup(LessonStatus::Reserved, ['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);
    ['lesson' => $confirmed] = pdSetup(LessonStatus::Confirmed, [
        'learner_id' => Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => gcseCurriculumId()])->id,
        'starts_at' => now()->addDays(4),
        'ends_at' => now()->addDays(4)->addHour(),
    ]);

    test()->actingAs($parent)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('upcoming', 2)
            ->where('upcoming.0.cancel_kind', 'skip')
            ->where('upcoming.1.cancel_kind', 'cancel'));
});

it('formats starts_at in the authenticated user\'s own timezone, not UTC', function () {
    Carbon::setTestNow('2026-01-10 00:00:00');

    $parent = User::factory()->create(['timezone' => 'Pacific/Kiritimati']); // UTC+14
    $tutor = TutorProfile::factory()->approved()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => gcseCurriculumId()]);

    // 23:30 UTC on 10 Jan is 13:30 on 11 Jan in UTC+14.
    $lesson = Lesson::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => Carbon::parse('2026-01-10 23:30:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-01-11 00:30:00', 'UTC'),
    ]);

    test()->actingAs($parent)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('upcoming.0.starts_at', 'Sun, 11 Jan 2026, 1:30 PM'));
});

it('never leaks a raw Eloquent model — only the presented keys are exposed', function () {
    ['parent' => $parent] = pdSetup();

    test()->actingAs($parent)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('upcoming.0', fn ($lesson) => $lesson
            ->hasAll(['id', 'starts_at', 'duration_minutes', 'status', 'subject', 'learner_display_name', 'tutor_display_name', 'price', 'weekly', 'cancel_window_hours', 'cancel_kind'])));
});
