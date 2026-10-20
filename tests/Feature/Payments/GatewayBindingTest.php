<?php

use App\Actions\Lessons\BookLesson;
use App\Actions\Payments\SaveTestCard;
use App\Enums\LessonStatus;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentMethod;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Models\User;
use App\Providers\PaymentGatewayServiceProvider;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\NoActivePaymentGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/**
 * CP4 (4f, R107 / ADR-016), reworked in 23a (R246): the fake gateway is resolved from the active
 * `payment_gateways` row and runs only on an allow-list of environments. The migration leaves the
 * fake row active under `testing`, so each test changes the environment (not the binding) and
 * asserts what the registry resolves.
 */
afterEach(function () {
    gbBootAs('testing');
});

/**
 * Re-runs the application as if it had booted under `$env`.
 */
function gbBootAs(string $env): void
{
    app()['env'] = $env;
    config(['app.env' => $env]);
}

it('resolves no gateway on production, staging and unknown environments, even with the fake row active', function (string $env) {
    gbBootAs($env);

    expect(PaymentGatewayAccount::query()->active()->value('code'))->toBe('fake')
        ->and(app(PaymentGatewayManager::class)->available())->toBeFalse()
        ->and(PaymentGatewayServiceProvider::fakeGatewayAllowed())->toBeFalse()
        ->and(fn () => app(PaymentGatewayManager::class)->active())->toThrow(NoActivePaymentGateway::class)
        ->and(fn () => app(PaymentGateway::class))->toThrow(NoActivePaymentGateway::class);
})->with(['production', 'staging', 'prod', '']);

it('resolves the fake gateway on local, testing and rehearsal', function (string $env) {
    gbBootAs($env);

    expect(PaymentGatewayServiceProvider::fakeGatewayAllowed())->toBeTrue()
        ->and(app(PaymentGatewayManager::class)->available())->toBeTrue()
        ->and(app(PaymentGateway::class))->toBeInstanceOf(FakePaymentGateway::class);
})->with(['local', 'testing', 'rehearsal']);

it('only allows a test card to be saved where the fake gateway is the active one', function (string $env, bool $allowed) {
    gbBootAs($env);

    expect(SaveTestCard::available())->toBe($allowed);
})->with([
    ['production', false],
    ['staging', false],
    ['local', true],
    ['testing', true],
    ['rehearsal', true],
]);

it('does not allow a test card to be saved when no gateway is active', function () {
    PaymentGatewayAccount::query()->update(['is_active' => false]);

    expect(SaveTestCard::available())->toBeFalse();
});

it('shares the test-mode flag with every page from the same predicate', function (string $env, bool $expected) {
    gbBootAs($env);

    $user = User::factory()->create();

    test()->actingAs($user)->get(route('learners.index'))
        ->assertInertia(fn ($page) => $page->where('paymentTestMode', $expected));
})->with([
    ['production', false],
    ['rehearsal', true],
]);

it('shares no test-mode flag when no gateway is active', function () {
    PaymentGatewayAccount::query()->update(['is_active' => false]);

    test()->actingAs(User::factory()->create())->get(route('learners.index'))
        ->assertInertia(fn ($page) => $page->where('paymentTestMode', false));
});

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

    expect(fn () => app(BookLesson::class))->toThrow(NoActivePaymentGateway::class);
});

it('charges through the fake gateway on rehearsal when recurring:charge runs, and charges nothing on production', function () {
    test()->travelTo(CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'));

    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $tutor = TutorProfile::factory()->bookable()->create();
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
