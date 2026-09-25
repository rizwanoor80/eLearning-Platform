<?php

use App\Actions\Lessons\BookLesson;
use App\Actions\Payments\SaveTestCard;
use App\Enums\LessonStatus;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Models\User;
use App\Providers\PaymentGatewayServiceProvider;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Artisan;

/**
 * CP4 (4f, R107 / ADR-016): the fake gateway is bound by an allow-list of environments and by
 * nothing else. These tests do not bind it by hand: they re-run the provider under each `APP_ENV`.
 */
afterEach(function () {
    gbBootAs('testing');
});

/**
 * Re-runs the provider's registration as if the application had booted under `$env`.
 */
function gbBootAs(string $env): void
{
    app()->offsetUnset(PaymentGateway::class);
    app()['env'] = $env;
    config(['app.env' => $env]);
    (new PaymentGatewayServiceProvider(app()))->register();
}

it('leaves the gateway unbound on production, staging and unknown environments', function (string $env) {
    gbBootAs($env);

    expect(app()->bound(PaymentGateway::class))->toBeFalse()
        ->and(PaymentGatewayServiceProvider::fakeGatewayAllowed())->toBeFalse()
        ->and(fn () => app(PaymentGateway::class))->toThrow(BindingResolutionException::class);
})->with(['production', 'staging', 'prod', '']);

it('binds the fake gateway on local, testing and rehearsal', function (string $env) {
    gbBootAs($env);

    expect(PaymentGatewayServiceProvider::fakeGatewayAllowed())->toBeTrue()
        ->and(app(PaymentGateway::class))->toBeInstanceOf(FakePaymentGateway::class);
})->with(['local', 'testing', 'rehearsal']);

it('only allows a test card to be saved where the fake gateway is bound', function (string $env, bool $allowed) {
    gbBootAs($env);

    expect(SaveTestCard::available())->toBe($allowed);
})->with([
    ['production', false],
    ['staging', false],
    ['local', true],
    ['testing', true],
    ['rehearsal', true],
]);

it('shares the test-mode flag with every page from the same predicate', function (string $env, bool $expected) {
    gbBootAs($env);

    $user = User::factory()->create();

    test()->actingAs($user)->get(route('learners.index'))
        ->assertInertia(fn ($page) => $page->where('paymentTestMode', $expected));
})->with([
    ['production', false],
    ['rehearsal', true],
]);

it('renders the banner through one component that all three portal pages use', function () {
    $component = file_get_contents(resource_path('js/components/TestModeBanner.vue'));

    expect($component)->toContain('paymentTestMode')->toContain('Test mode');

    foreach (['payment-methods/Create', 'weekly-slots/Create', 'learners/Show'] as $page) {
        expect(file_get_contents(resource_path("js/pages/{$page}.vue")))->toContain('<TestModeBanner');
    }
});

it('resolves BookLesson from the container on rehearsal with no manual binding', function () {
    gbBootAs('rehearsal');

    expect(app(BookLesson::class))->toBeInstanceOf(BookLesson::class);
});

it('cannot resolve BookLesson on production', function () {
    gbBootAs('production');

    expect(fn () => app(BookLesson::class))->toThrow(BindingResolutionException::class);
});

it('charges through the fake gateway on rehearsal when recurring:charge runs, and charges nothing on production', function () {
    test()->travelTo(CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'));

    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $tutor = TutorProfile::factory()->approved()->create();
    $slot = RecurringSlot::factory()->create([
        'learner_id' => $learner->id,
        'tutor_profile_id' => $tutor->id,
        'timezone' => 'UTC',
        'starts_on' => '2026-09-14',
        'generated_until' => '2026-09-13',
    ]);
    PaymentMethod::factory()->create(['account_user_id' => $parent->id]);
    $start = CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC');
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'booked_by_user_id' => $parent->id,
        'recurring_slot_id' => $slot->id,
        'starts_at' => $start,
        'ends_at' => $start->addHour(),
        'next_charge_at' => $start->subHours(48),
    ]);

    gbBootAs('production');
    Artisan::call('recurring:charge');
    expect(Artisan::output())->toContain('No payment gateway is configured')
        ->and(Payment::query()->count())->toBe(0)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Reserved);

    gbBootAs('rehearsal');
    Artisan::call('recurring:charge');

    expect(Payment::query()->count())->toBe(1)
        ->and(Payment::query()->first()->gateway)->toBe('fake')
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
});
