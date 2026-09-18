<?php

use App\Events\Tutor\TutorPermitExpired;
use App\Events\Tutor\TutorPermitExpiring;
use App\Mail\Tutor\TutorPermitExpiredMail;
use App\Mail\Tutor\TutorPermitExpiringMail;
use App\Models\AuditLog;
use App\Models\TutorProfile;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

function approvedTutorExpiringInDays(int $days): TutorProfile
{
    return TutorProfile::factory()->approved()->create([
        'permit_expires_at' => now()->addDays($days)->toDateString(),
    ]);
}

beforeEach(function () {
    $this->travelTo(now()->startOfDay()->addHours(6));
});

it('sends the 30-day warning once a permit is within 30 days', function () {
    Event::fake();
    $profile = approvedTutorExpiringInDays(20);

    $this->artisan('tutors:check-permits')->assertSuccessful();

    Event::assertDispatched(TutorPermitExpiring::class, fn ($e) => $e->profile->is($profile) && $e->daysRemaining === 20);
    expect(AuditLog::query()->where('action', 'tutor.permit_warning_30d')->count())->toBe(1);
});

it('sends the 7-day warning once a permit is within 7 days', function () {
    Event::fake();
    $profile = approvedTutorExpiringInDays(5);

    $this->artisan('tutors:check-permits')->assertSuccessful();

    Event::assertDispatched(TutorPermitExpiring::class, fn ($e) => $e->profile->is($profile) && $e->daysRemaining === 5);
    expect(AuditLog::query()->where('action', 'tutor.permit_warning_7d')->count())->toBe(1);
});

it('sends the expired notice when the permit is no longer in the future', function () {
    Event::fake();
    $profile = approvedTutorExpiringInDays(0);

    $this->artisan('tutors:check-permits')->assertSuccessful();

    Event::assertDispatched(TutorPermitExpired::class, fn ($e) => $e->profile->is($profile));
    // Auto-hide is bookable()'s job, not this command's: the tutor is excluded
    // from the scope with no status change here.
    expect(TutorProfile::bookable()->whereKey($profile->id)->exists())->toBeFalse()
        ->and($profile->fresh()->status->value)->toBe('approved');
});

it('is safe to run twice: a repeat run sends nothing new', function () {
    Event::fake();
    approvedTutorExpiringInDays(5);

    $this->artisan('tutors:check-permits');
    $this->artisan('tutors:check-permits');

    Event::assertDispatchedTimes(TutorPermitExpiring::class, 1);
    expect(AuditLog::query()->count())->toBe(1);
});

it('starts a fresh warning cycle when the permit is renewed', function () {
    Event::fake();
    $profile = approvedTutorExpiringInDays(5);
    $this->artisan('tutors:check-permits');

    $profile->forceFill(['permit_expires_at' => now()->addDays(6)->toDateString()])->save();
    $this->artisan('tutors:check-permits');

    Event::assertDispatchedTimes(TutorPermitExpiring::class, 2);
});

it('ignores tutors that are not approved and permits more than 30 days out', function () {
    Event::fake();
    TutorProfile::factory()->create(['permit_expires_at' => now()->addDays(3)->toDateString()]);
    approvedTutorExpiringInDays(90);

    $this->artisan('tutors:check-permits')->assertSuccessful();

    Event::assertNotDispatched(TutorPermitExpiring::class);
    Event::assertNotDispatched(TutorPermitExpired::class);
});

it('queues the expiring and expired emails from the sender settings', function () {
    Mail::fake();
    $expiring = approvedTutorExpiringInDays(5);
    $expired = approvedTutorExpiringInDays(0);

    $this->artisan('tutors:check-permits');

    Mail::assertQueued(TutorPermitExpiringMail::class, fn ($m) => $m->hasTo($expiring->user->email));
    Mail::assertQueued(TutorPermitExpiredMail::class, fn ($m) => $m->hasTo($expired->user->email));
});

it('is registered on the daily schedule', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command);

    expect($commands->contains(fn ($command) => str_contains((string) $command, 'tutors:check-permits')))->toBeTrue();
});
