<?php

use App\Enums\LessonStatus;
use App\Mail\Lessons\LessonReminderMail;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('sends the 1h reminder for a confirmed lesson starting within the hour', function () {
    Mail::fake();
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->startingAt(now()->addMinutes(30))->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
    ]);

    $this->artisan('lessons:send-reminders')->assertSuccessful();

    Mail::assertQueued(LessonReminderMail::class, fn ($m) => $m->window === '1h' && $m->hasTo($parent->email));
    Mail::assertQueued(LessonReminderMail::class, fn ($m) => $m->window === '1h' && $m->hasTo($tutor->user->email));
    Mail::assertQueued(LessonReminderMail::class, 2);
    expect($lesson->fresh()->reminder_1h_sent_at)->not->toBeNull()
        ->and($lesson->fresh()->reminder_24h_sent_at)->toBeNull();
});

it('sends the 24h reminder for a confirmed lesson starting tomorrow', function () {
    Mail::fake();
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->startingAt(now()->addHours(20))->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
    ]);

    $this->artisan('lessons:send-reminders')->assertSuccessful();

    Mail::assertQueued(LessonReminderMail::class, fn ($m) => $m->window === '24h' && $m->hasTo($parent->email));
    Mail::assertQueued(LessonReminderMail::class, fn ($m) => $m->window === '24h' && $m->hasTo($tutor->user->email));
    Mail::assertQueued(LessonReminderMail::class, 2);
    expect($lesson->fresh()->reminder_24h_sent_at)->not->toBeNull()
        ->and($lesson->fresh()->reminder_1h_sent_at)->toBeNull();
});

it('sends neither reminder for a lesson more than 24h away', function () {
    Mail::fake();
    $lesson = Lesson::factory()->startingAt(now()->addHours(25))->create();

    $this->artisan('lessons:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
    expect($lesson->fresh()->reminder_24h_sent_at)->toBeNull()
        ->and($lesson->fresh()->reminder_1h_sent_at)->toBeNull();
});

it('sends neither reminder for a lesson that has already started', function () {
    Mail::fake();
    $lesson = Lesson::factory()->startingAt(now()->subMinutes(5))->create();

    $this->artisan('lessons:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('never reminds a lesson that is not confirmed', function () {
    Mail::fake();
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->startingAt(now()->addMinutes(30))->create();

    $this->artisan('lessons:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
    expect($lesson->fresh()->reminder_1h_sent_at)->toBeNull();
});

it('does not resend a reminder already marked sent', function () {
    Mail::fake();
    $lesson = Lesson::factory()->startingAt(now()->addMinutes(30))->create([
        'reminder_1h_sent_at' => now()->subMinute(),
    ]);

    $this->artisan('lessons:send-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('is a no-op the second time it runs, for both windows', function () {
    Mail::fake();
    Lesson::factory()->startingAt(now()->addMinutes(30))->create();
    Lesson::factory()->startingAt(now()->addHours(20))->create();

    $this->artisan('lessons:send-reminders')->assertSuccessful();
    $firstRunCount = Mail::queued(LessonReminderMail::class)->count();

    $this->artisan('lessons:send-reminders')->assertSuccessful();
    $secondRunCount = Mail::queued(LessonReminderMail::class)->count();

    expect($firstRunCount)->toBe(4)
        ->and($secondRunCount)->toBe(4);
});

it('renders the reminder email for both windows without error', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $lesson = Lesson::factory()->startingAt(now()->addHours(20))->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
    ]);

    $html24h = (new LessonReminderMail($lesson, $parent, '24h'))->render();
    $html1h = (new LessonReminderMail($lesson, $parent, '1h'))->render();

    expect($html24h)->toContain('tomorrow')
        ->and($html1h)->toContain('in about an hour');
});
