<?php

use App\Actions\Payments\CancelCheckoutIntent;
use App\Enums\CurriculumCode;
use App\Enums\LessonStatus;
use App\Enums\LevelTier;
use App\Enums\PaymentStatus;
use App\Models\AvailabilityRule;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PriceBand;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Payments\FakeStripeHttp;

/**
 * 23c (R248): the parent's Stripe checkout. The browser confirms (and so only holds) a manual-capture
 * PaymentIntent that the server created for the quoted price; `BookLesson` then captures it. Every
 * Stripe call is the faked HTTP client; the booking rules themselves are BookLessonScreenTest's.
 */
const SC_SLOT = '2026-09-15T09:00:00Z';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC'));
    $this->http = FakeStripeHttp::install();
    PaymentGatewayAccount::factory()->active()->create();
    Mail::fake();
});

afterEach(function () {
    FakeStripeHttp::uninstall();
});

/**
 * A tutor open on Tuesdays 09:00–12:00 UTC inside a GCSE price band, as BookLessonScreenTest sets one up.
 *
 * @return array{tutor: TutorProfile, curriculum_id: int, subject_id: int}
 */
function scTutor(int $rate = 10000): array
{
    $curriculum = Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0]);
    $subject = Subject::factory()->create();
    $tutor = TutorProfile::factory()->approved()->create(['hourly_rate' => $rate]);

    TutorSubject::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'curriculum_id' => $curriculum->id,
        'subject_id' => $subject->id,
        'level_tier' => LevelTier::LowerSecondary,
    ]);

    PriceBand::query()->firstOrCreate(
        ['curriculum_id' => $curriculum->id, 'level_tier' => LevelTier::LowerSecondary, 'effective_from' => '2000-01-01'],
        ['min_rate' => 5000, 'max_rate' => 20000],
    );

    AvailabilityRule::factory()->create([
        'tutor_profile_id' => $tutor->id, 'weekday' => 2, 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'timezone' => 'UTC',
    ]);

    return ['tutor' => $tutor->fresh(), 'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id];
}

/** The token the booking page carries for this learner, read the way the browser does: from the owner's own GET. */
function scQuoteToken(TutorProfile $tutor, Learner $learner): string
{
    $owner = User::query()->findOrFail($learner->account_user_id);
    $response = test()->actingAs($owner)->get(route('tutors.book', $tutor->id).'?starts_at='.rawurlencode(SC_SLOT));
    $row = collect($response->inertiaPage()['props']['learners'] ?? [])->firstWhere('id', $learner->id);
    auth()->logout();

    return $row['quote_token'];
}

/**
 * @return array{setup: array, parent: User, learner: Learner, tutor: TutorProfile, token: string, amount: int}
 */
function scBooking(): array
{
    $setup = scTutor();
    $parent = User::factory()->create(['timezone' => 'UTC']);
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => gcseCurriculumId()]);

    return [
        'setup' => $setup,
        'parent' => $parent,
        'learner' => $learner,
        'tutor' => $setup['tutor'],
        'token' => scQuoteToken($setup['tutor'], $learner),
        'amount' => $setup['tutor']->trialPrice()->toFils(),
    ];
}

/** The intent as Stripe holds it after the browser confirmed, bound to the booking in `$b`. */
function scHeld(array $b, array $overrides = [], array $metadata = []): array
{
    return array_replace([
        'id' => 'pi_test_1',
        'object' => 'payment_intent',
        'status' => 'requires_capture',
        'capture_method' => 'manual',
        'amount' => $b['amount'],
        'amount_capturable' => $b['amount'],
        'amount_received' => 0,
        'currency' => 'aed',
        'livemode' => false,
        'latest_charge' => 'ch_test_1',
        'metadata' => $metadata + [
            'kind' => 'checkout',
            'account_user_id' => (string) $b['parent']->id,
            'learner_id' => (string) $b['learner']->id,
            'tutor_profile_id' => (string) $b['tutor']->id,
            'starts_at' => SC_SLOT,
        ],
    ], $overrides);
}

function scCaptured(array $b): array
{
    return scHeld($b, [
        'status' => 'succeeded',
        'amount_capturable' => 0,
        'amount_received' => $b['amount'],
        'latest_charge' => ['id' => 'ch_test_1', 'object' => 'charge', 'refunded' => false, 'disputed' => false, 'amount_refunded' => 0],
    ]);
}

function scForm(array $b, array $extra = []): array
{
    return array_merge([
        'learner_id' => $b['learner']->id,
        'tutor_id' => $b['tutor']->id,
        'curriculum_id' => $b['setup']['curriculum_id'],
        'subject_id' => $b['setup']['subject_id'],
        'starts_at' => SC_SLOT,
        'quote_token' => $b['token'],
    ], $extra);
}

function scIntentForm(array $b, array $extra = []): array
{
    return $extra + [
        'learner_id' => $b['learner']->id,
        'tutor_id' => $b['tutor']->id,
        'starts_at' => SC_SLOT,
        'quote_token' => $b['token'],
    ];
}

// --- The intent the browser confirms ---------------------------------------------------------------

it('creates a manual-capture, card-only intent for the server\'s own price, bound to the booking and free of personal data', function () {
    $b = scBooking();
    $this->http->reply('POST /v1/payment_intents', ['id' => 'pi_test_9', 'object' => 'payment_intent', 'client_secret' => 'pi_test_9_secret_x', 'status' => 'requires_payment_method']);

    $this->actingAs($b['parent'])
        ->postJson(route('payments.checkout-intent.store'), scIntentForm($b, ['amount' => 1, 'currency' => 'usd', 'capture_method' => 'automatic']))
        ->assertOk()
        ->assertExactJson(['payment_intent_id' => 'pi_test_9', 'client_secret' => 'pi_test_9_secret_x']);

    [$request] = $this->http->requestsTo('POST /v1/payment_intents');

    expect($request['params'])->toBe([
        'amount' => $b['amount'],
        'currency' => 'aed',
        'capture_method' => 'manual',
        'payment_method_types' => ['card'],
        'metadata' => [
            'kind' => 'checkout',
            'account_user_id' => (string) $b['parent']->id,
            'learner_id' => (string) $b['learner']->id,
            'tutor_profile_id' => (string) $b['tutor']->id,
            'starts_at' => SC_SLOT,
        ],
    ])->and(json_encode($request['params']))->not->toContain($b['parent']->email)->not->toContain($b['learner']->display_name);
});

it('refuses to create an intent for a booking that could not be made, and asks Stripe nothing', function (string $case) {
    $b = scBooking();
    $form = scIntentForm($b);
    $user = $b['parent'];

    match ($case) {
        'someone else\'s learner' => $user = User::factory()->create(),
        'an unknown tutor' => $form['tutor_id'] = 999999,
        'a tutor who is not bookable' => $b['tutor']->forceFill(['status' => 'suspended'])->save(),
        'a stale quote' => $form['quote_token'] = str_repeat('a', 64),
        'a slot nobody offers' => $form['starts_at'] = '2026-09-16T09:00:00Z',
        'a time that is not a time' => $form['starts_at'] = '2026-13-45T09:00:00Z',
    };

    $this->actingAs($user)->postJson(route('payments.checkout-intent.store'), $form)->assertUnprocessable();

    expect($this->http->requests)->toBeEmpty();
})->with(['someone else\'s learner', 'an unknown tutor', 'a tutor who is not bookable', 'a stale quote', 'a slot nobody offers', 'a time that is not a time']);

it('is closed to guests, and to everyone when Stripe is not the active gateway', function () {
    $b = scBooking();

    $this->postJson(route('payments.checkout-intent.store'), scIntentForm($b))->assertUnauthorized();

    PaymentGatewayAccount::factory()->fake()->active()->create();

    $this->actingAs($b['parent'])->postJson(route('payments.checkout-intent.store'), scIntentForm($b))->assertNotFound();
    expect($this->http->requests)->toBeEmpty();
});

// --- Booking with a held intent ----------------------------------------------------------------------

it('books a lesson by capturing the held intent: confirmed, one captured payment, ledger at zero', function () {
    $b = scBooking();
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', scHeld($b))
        ->reply('POST /v1/payment_intents/pi_test_1/capture', scCaptured($b));

    $this->actingAs($b['parent'])->post(route('lessons.store'), scForm($b, ['payment_intent_id' => 'pi_test_1']))
        ->assertRedirect(route('learners.show', $b['learner']))
        ->assertSessionHasNoErrors();

    $lesson = Lesson::query()->sole();
    $payment = Payment::query()->sole();

    expect($lesson->status)->toBe(LessonStatus::Confirmed)
        ->and($lesson->price->toFils())->toBe($b['amount'])
        ->and($payment->gateway)->toBe('stripe')
        ->and($payment->gateway_ref)->toBe('pi_test_1')
        ->and($payment->status)->toBe(PaymentStatus::Captured)
        ->and($payment->amount->toFils())->toBe($b['amount'])
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0)
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/cancel'))->toBeEmpty();
});

it('writes nothing, and asks Stripe nothing, when the card step was skipped or malformed', function (array $extra) {
    $b = scBooking();

    $this->actingAs($b['parent'])->post(route('lessons.store'), scForm($b, $extra))->assertSessionHasErrors();

    expect(Lesson::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and($this->http->requests)->toBeEmpty();
})->with([
    'no intent id' => [[]],
    'a blank intent id' => [['payment_intent_id' => '']],
    'a setup intent id' => [['payment_intent_id' => 'seti_test_1']],
    'a path in the id' => [['payment_intent_id' => 'pi_x/../v1/customers']],
]);

it('releases the hold and books nothing when the intent is for a different booking', function (array $metadata) {
    $b = scBooking();
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', scHeld($b, [], $metadata))
        ->reply('GET /v1/payment_intents/pi_test_1', scHeld($b, [], $metadata))
        ->reply('POST /v1/payment_intents/pi_test_1/cancel', scHeld($b, ['status' => 'canceled']));

    $this->actingAs($b['parent'])->post(route('lessons.store'), scForm($b, ['payment_intent_id' => 'pi_test_1']))
        ->assertSessionHasErrors(['slot' => 'Payment did not go through. Nothing was booked.']);

    expect(Lesson::query()->sole()->status)->toBe(LessonStatus::Expired)
        ->and(Payment::query()->sole()->status)->toBe(PaymentStatus::Failed)
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/capture'))->toBeEmpty()
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/cancel'))->toHaveCount(1);
})->with([
    'another learner' => [['learner_id' => '999999']],
    'another tutor' => [['tutor_profile_id' => '999999']],
    'another time' => [['starts_at' => '2026-09-15T10:00:00Z']],
]);

it('does not touch an intent that belongs to another parent', function () {
    $b = scBooking();
    $stranger = User::factory()->create();
    $held = scHeld($b, [], ['account_user_id' => (string) $stranger->id]);
    $this->http->reply('GET /v1/payment_intents/pi_test_1', $held)->reply('GET /v1/payment_intents/pi_test_1', $held);

    $this->actingAs($b['parent'])->post(route('lessons.store'), scForm($b, ['payment_intent_id' => 'pi_test_1']))
        ->assertSessionHasErrors(['slot' => 'Payment did not go through. Nothing was booked.']);

    expect($this->http->requestsTo('POST'))->toBeEmpty();
});

it('releases the hold when the slot was taken after the card was confirmed', function () {
    $b = scBooking();
    $other = scBooking();
    Lesson::factory()->create([
        'tutor_profile_id' => $b['tutor']->id,
        'starts_at' => CarbonImmutable::parse(SC_SLOT),
        'ends_at' => CarbonImmutable::parse(SC_SLOT)->addHour(),
        'status' => LessonStatus::Confirmed,
    ]);
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', scHeld($b))
        ->reply('POST /v1/payment_intents/pi_test_1/cancel', scHeld($b, ['status' => 'canceled']));

    $this->actingAs($b['parent'])->post(route('lessons.store'), scForm($b, ['payment_intent_id' => 'pi_test_1']))->assertSessionHasErrors('slot');

    expect(Payment::query()->count())->toBe(0)
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/capture'))->toBeEmpty()
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/cancel'))->toHaveCount(1)
        ->and($other['learner']->id)->not->toBe($b['learner']->id);
});

it('tells the parent the truth, without an internal id, when the money was taken but the lesson could not be confirmed', function () {
    $b = scBooking();
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', scHeld($b))
        ->reply('POST /v1/payment_intents/pi_test_1/capture', scCaptured($b))
        ->reply('GET /v1/payment_intents/pi_test_1', scCaptured($b));
    // The payment row is written once, after the capture; failing that write is the "taken but unconfirmed" case.
    Payment::updating(fn () => throw new RuntimeException('database went away'));

    $response = $this->actingAs($b['parent'])->post(route('lessons.store'), scForm($b, ['payment_intent_id' => 'pi_test_1']));

    $response->assertSessionHasErrors('slot');
    $message = session('errors')->first('slot');

    expect($message)->toContain('payment was taken')->not->toContain((string) Lesson::query()->sole()->id)
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/cancel'))->toBeEmpty();
});

it('leaves the fake driver alone: its booking ignores an intent id and never calls Stripe', function () {
    PaymentGatewayAccount::factory()->fake()->active()->create();
    $b = scBooking();

    $this->actingAs($b['parent'])->post(route('lessons.store'), scForm($b, ['payment_intent_id' => 'pi_test_1']))->assertSessionHasNoErrors();

    expect(Payment::query()->sole()->gateway)->toBe('fake')
        ->and($this->http->requests)->toBeEmpty();
});

it('shows the booking page the publishable key only when Stripe is active', function () {
    $b = scBooking();

    $this->actingAs($b['parent'])->get(route('tutors.book', $b['tutor']->id).'?starts_at='.rawurlencode(SC_SLOT))->assertInertia(fn ($page) => $page
        ->where('stripe.publishable_key', 'pk_test_example')
        ->where('learners.0.amount_fils', $b['amount']));

    PaymentGatewayAccount::factory()->fake()->active()->create();

    $this->actingAs($b['parent'])->get(route('tutors.book', $b['tutor']->id).'?starts_at='.rawurlencode(SC_SLOT))->assertInertia(fn ($page) => $page->where('stripe', null));
});

// --- CancelCheckoutIntent ----------------------------------------------------------------------------

it('cancels only a checkout hold of the caller\'s own, and is silent about everything else', function (array $intent, bool $cancelled) {
    $parent = User::factory()->create();
    $this->http->reply('GET /v1/payment_intents/pi_test_1', array_replace_recursive([
        'id' => 'pi_test_1', 'object' => 'payment_intent', 'status' => 'requires_capture',
        'metadata' => ['kind' => 'checkout', 'account_user_id' => (string) $parent->id],
    ], $intent));
    $this->http->reply('POST /v1/payment_intents/pi_test_1/cancel', ['id' => 'pi_test_1', 'object' => 'payment_intent', 'status' => 'canceled']);

    app(CancelCheckoutIntent::class)($parent, 'pi_test_1');

    expect($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/cancel'))->toHaveCount($cancelled ? 1 : 0);
})->with([
    'a held checkout' => [[], true],
    'one not yet confirmed' => [['status' => 'requires_payment_method'], true],
    'one already captured' => [['status' => 'succeeded'], false],
    'one already cancelled' => [['status' => 'canceled'], false],
    'another parent\'s' => [['metadata' => ['account_user_id' => '999999']], false],
    'a saved-card charge' => [['metadata' => ['kind' => 'recurring']], false],
]);

it('swallows a Stripe failure while releasing a hold', function () {
    $parent = User::factory()->create();
    $this->http->reply('GET /v1/payment_intents/pi_gone', ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such payment_intent']], 404);

    app(CancelCheckoutIntent::class)($parent, 'pi_gone');

    expect($this->http->requestsTo('POST'))->toBeEmpty();
});
