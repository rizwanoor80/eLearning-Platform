<?php

use App\Actions\Payments\CancelCheckoutIntent;
use App\Enums\CurriculumCode;
use App\Enums\LessonStatus;
use App\Enums\LevelTier;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Models\AvailabilityRule;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentRefund;
use App\Models\PriceBand;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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
        'attempt_id' => '6f1b1c6e-3d0b-4a53-9c1e-0f6a0a1b2c3d',
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

it('refunds a captured-but-unclean payment to the card by itself, and lets ledger:verify and the payment agree (R259 (2))', function () {
    Mail::fake();
    $b = scBooking();
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', scHeld($b))
        ->reply('POST /v1/payment_intents/pi_test_1/capture', array_replace(scCaptured($b), ['status' => 'processing']))
        ->reply('GET /v1/payment_intents/pi_test_1', scCaptured($b))
        ->reply('GET /v1/refunds', ['object' => 'list', 'data' => [], 'has_more' => false])
        ->reply('POST /v1/refunds', ['id' => 're_test_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => $b['amount'], 'currency' => 'aed', 'payment_intent' => 'pi_test_1']);

    $this->actingAs($b['parent'])->post(route('lessons.store'), scForm($b, ['payment_intent_id' => 'pi_test_1']))->assertSessionHasErrors('slot');

    $payment = Payment::query()->sole();
    $refunds = $this->http->requestsTo('POST /v1/refunds');

    expect(session('errors')->first('slot'))->toContain('payment was taken')
        ->and($payment->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->refunded_amount?->toFils())->toBe($b['amount'])
        ->and(PaymentRefund::query()->where('payment_id', $payment->id)->sole()->status)->toBe(PaymentRefundStatus::Succeeded)
        ->and($refunds)->toHaveCount(1)
        ->and($this->http->idempotencyKeyOf($refunds[0]))->toBe('test:testing:unverified-pi_test_1')
        ->and(Lesson::query()->sole()->status)->toBe(LessonStatus::Expired);

    $this->travel(2)->hours();

    expect(app(LedgerService::class)->strandedPayments())->toBeEmpty();
    $this->artisan('ledger:verify')->assertSuccessful();
});

it('keeps the payment visible to ledger:verify, and tells admins, when the gateway refuses the automatic refund (R259 (2))', function () {
    Event::fake([PaymentNeedsAttention::class]);
    $b = scBooking();
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', scHeld($b))
        ->reply('POST /v1/payment_intents/pi_test_1/capture', array_replace(scCaptured($b), ['status' => 'processing']))
        ->reply('GET /v1/payment_intents/pi_test_1', scCaptured($b))
        ->reply('GET /v1/refunds', ['object' => 'list', 'data' => [], 'has_more' => false])
        ->reply('POST /v1/refunds', ['error' => ['type' => 'invalid_request_error', 'code' => 'charge_already_refunded', 'message' => 'no']], 400);

    $this->actingAs($b['parent'])->post(route('lessons.store'), scForm($b, ['payment_intent_id' => 'pi_test_1']))->assertSessionHasErrors('slot');

    $payment = Payment::query()->sole();

    expect($payment->status)->toBe(PaymentStatus::Captured)
        ->and(PaymentRefund::query()->where('payment_id', $payment->id)->sole()->status)->toBe(PaymentRefundStatus::Failed);
    Event::assertDispatched(PaymentNeedsAttention::class, fn ($e) => $e->reason === PaymentNeedsAttention::REFUND_FAILED);

    $this->travel(2)->hours();

    expect(app(LedgerService::class)->strandedPayments()->pluck('payment_id')->all())->toBe([$payment->id]);
});

it('asks the parent to retry, without calling it a decline, when the gateway is unreachable during the capture (R259 (1))', function () {
    $b = scBooking();
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', scHeld($b))
        ->reply('POST /v1/payment_intents/pi_test_1/capture', ['error' => ['type' => 'api_error', 'message' => 'x']], 500);

    $this->actingAs($b['parent'])->post(route('lessons.store'), scForm($b, ['payment_intent_id' => 'pi_test_1']))->assertSessionHasErrors('retry');

    $payment = Payment::query()->sole();

    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('gateway_unavailable')
        ->and($payment->gateway_ref)->toBeNull()
        ->and($payment->raw_response['checkout_intent'])->toBe('pi_test_1')
        ->and(Lesson::query()->sole()->status)->toBe(LessonStatus::Expired)
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/cancel'))->toBeEmpty();

    // The retry on the same hold succeeds: one hold, one capture call that lands, one confirmed lesson.
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', scHeld($b))
        ->reply('POST /v1/payment_intents/pi_test_1/capture', scCaptured($b));

    $this->actingAs($b['parent'])->post(route('lessons.store'), scForm($b, ['payment_intent_id' => 'pi_test_1']))->assertSessionDoesntHaveErrors();

    expect(Lesson::query()->where('status', LessonStatus::Confirmed)->count())->toBe(1)
        ->and(Payment::query()->where('status', PaymentStatus::Captured)->count())->toBe(1)
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/capture'))->toHaveCount(2);
});

it('lets one browser attempt hold the booking lock at a time, so a double click cannot book twice (R259 (3))', function () {
    $b = scBooking();
    $lock = Cache::lock('book-intent:pi_test_1', 120);
    expect($lock->get())->toBeTrue();

    $this->actingAs($b['parent'])->post(route('lessons.store'), scForm($b, ['payment_intent_id' => 'pi_test_1']))->assertSessionHasErrors('retry');

    expect(Lesson::query()->count())->toBe(0)
        ->and($this->http->requests)->toBeEmpty();

    $lock->release();
});

it('derives the PaymentIntent idempotency key from the browser attempt, so a double click creates one intent (R259 (3))', function () {
    $b = scBooking();
    $this->http
        ->reply('POST /v1/payment_intents', ['id' => 'pi_test_9', 'object' => 'payment_intent', 'client_secret' => 'pi_test_9_secret_x', 'status' => 'requires_payment_method'])
        ->reply('POST /v1/payment_intents', ['id' => 'pi_test_9', 'object' => 'payment_intent', 'client_secret' => 'pi_test_9_secret_x', 'status' => 'requires_payment_method'])
        ->reply('POST /v1/payment_intents', ['id' => 'pi_test_10', 'object' => 'payment_intent', 'client_secret' => 'pi_test_10_secret_x', 'status' => 'requires_payment_method']);

    $url = route('payments.checkout-intent.store');
    $this->actingAs($b['parent'])->postJson($url, scIntentForm($b))->assertOk();
    $this->actingAs($b['parent'])->postJson($url, scIntentForm($b))->assertOk();
    $this->actingAs($b['parent'])->postJson($url, scIntentForm($b, ['attempt_id' => 'b3a1d0f2-0000-4000-8000-000000000001']))->assertOk();

    $keys = collect($this->http->requestsTo('POST /v1/payment_intents'))->map(fn ($r) => $this->http->idempotencyKeyOf($r));

    expect($keys[0])->toStartWith('test:testing:checkout-')->toBe($keys[1])->not->toBe($keys[2]);
});

it('refuses an intent request that carries no attempt id', function () {
    $b = scBooking();
    $form = scIntentForm($b);
    unset($form['attempt_id']);

    $this->actingAs($b['parent'])->postJson(route('payments.checkout-intent.store'), $form)->assertUnprocessable()->assertJsonValidationErrors('attempt_id');
});

it('releases the hold when the quote went stale or the tutor stopped being bookable after the card was confirmed', function (string $case) {
    $b = scBooking();
    $form = scForm($b, ['payment_intent_id' => 'pi_test_1']);

    match ($case) {
        'a stale quote' => $form['quote_token'] = str_repeat('a', 64),
        'a tutor who is not bookable' => $b['tutor']->forceFill(['status' => 'suspended'])->save(),
    };

    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', scHeld($b))
        ->reply('POST /v1/payment_intents/pi_test_1/cancel', scHeld($b, ['status' => 'canceled']));

    $this->actingAs($b['parent'])->post(route('lessons.store'), $form)->assertSessionHasErrors('slot');

    expect(Lesson::query()->count())->toBe(0)
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/capture'))->toBeEmpty()
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/cancel'))->toHaveCount(1);
})->with(['a stale quote', 'a tutor who is not bookable']);

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

// --- 24d (R262): the booking page after the bank's own 3-D Secure page ----------------------------------

it('draws the same booking page when Stripe sends the browser back with its return parameters, and asks Stripe nothing', function () {
    $b = scBooking();
    $return = '&payment_intent=pi_test_1&payment_intent_client_secret=pi_test_1_secret_x&redirect_status=succeeded';

    $this->actingAs($b['parent'])->get(route('tutors.book', $b['tutor']->id).'?starts_at='.rawurlencode(SC_SLOT).$return)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('lessons/Book')
            ->where('slot_available', true)
            ->where('starts_at', SC_SLOT)
            ->where('stripe.publishable_key', 'pk_test_example')
            ->where('learners.0.amount_fils', $b['amount'])
            ->where('learners.0.quote_token', $b['token']));

    // The page resumes in the browser (it reads the intent with its own client secret); the server created no hold here.
    expect($this->http->requestsTo('POST /v1/payment_intents'))->toBeEmpty()
        ->and(Payment::query()->count())->toBe(0);
});

it('books from a hold only once: a reload of the bank\'s return that posts the same intent again creates nothing new', function () {
    $b = scBooking();
    $this->http
        ->reply('GET /v1/payment_intents/pi_test_1', scHeld($b))
        ->reply('POST /v1/payment_intents/pi_test_1/capture', scCaptured($b));

    // After the redirect the page posts the remembered learner and subject with the returned intent's id.
    $this->actingAs($b['parent'])->post(route('lessons.store'), scForm($b, ['payment_intent_id' => 'pi_test_1']))
        ->assertRedirect(route('learners.show', $b['learner']));

    // The same return replayed (a second tab, a back button): the intent is spent now.
    $this->http->reply('GET /v1/payment_intents/pi_test_1', scCaptured($b));

    $this->actingAs($b['parent'])->post(route('lessons.store'), scForm($b, ['payment_intent_id' => 'pi_test_1']))
        ->assertSessionHasErrors();

    expect(Lesson::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/capture'))->toHaveCount(1);
});

// --- 25d (R269): the page says "approved" only when the server confirms the hold ---------------------

function scConfirmForm(array $b, array $extra = []): array
{
    return $extra + ['payment_intent_id' => 'pi_test_1', 'tutor_id' => $b['tutor']->id, 'starts_at' => SC_SLOT];
}

it('confirms a hold that is the caller\'s own, for this tutor and slot and the price quoted now, and changes nothing', function () {
    $b = scBooking();
    $this->http->reply('GET /v1/payment_intents/pi_test_1', scHeld($b));

    $this->actingAs($b['parent'])->postJson(route('payments.checkout-intent.confirm'), scConfirmForm($b))
        ->assertOk()
        ->assertExactJson(['confirmed' => true, 'learner_id' => $b['learner']->id]);

    expect($this->http->requests)->toHaveCount(1)
        ->and($this->http->requestsTo('GET /v1/payment_intents/pi_test_1'))->toHaveCount(1)
        ->and(Payment::query()->count())->toBe(0)
        ->and(Lesson::query()->count())->toBe(0);
});

it('says the payment could not be confirmed for anything but a clean hold', function (string $case) {
    $b = scBooking();
    $form = scConfirmForm($b);
    $intent = match ($case) {
        'another parent\'s hold' => scHeld($b, [], ['account_user_id' => (string) ($b['parent']->id + 1000)]),
        'another tutor' => scHeld($b, [], ['tutor_profile_id' => (string) ($b['tutor']->id + 1000)]),
        'another start time' => scHeld($b, [], ['starts_at' => '2026-09-22T09:00:00Z']),
        'a smaller amount' => scHeld($b, ['amount' => $b['amount'] - 100, 'amount_capturable' => $b['amount'] - 100]),
        'a larger amount' => scHeld($b, ['amount' => $b['amount'] + 100, 'amount_capturable' => $b['amount'] + 100]),
        'an amount only partly held' => scHeld($b, ['amount_capturable' => $b['amount'] - 100]),
        'a hold not yet confirmed' => scHeld($b, ['status' => 'requires_payment_method', 'amount_capturable' => 0]),
        'a hold the bank is still checking' => scHeld($b, ['status' => 'processing', 'amount_capturable' => 0]),
        'a spent intent' => scCaptured($b),
        'a cancelled intent' => scHeld($b, ['status' => 'canceled', 'amount_capturable' => 0]),
        'automatic capture' => scHeld($b, ['capture_method' => 'automatic']),
        'a live-mode intent on a test row' => scHeld($b, ['livemode' => true]),
        'another currency' => scHeld($b, ['currency' => 'usd']),
        'a saved-card charge' => scHeld($b, [], ['kind' => 'recurring']),
        'an intent that already became a payment' => scHeld($b),
        'an unknown learner' => scHeld($b, [], ['learner_id' => '99999999']),
        'Stripe not answering' => null,
    };

    if ($case === 'an intent that already became a payment') {
        Payment::factory()->create(['gateway' => 'stripe', 'gateway_ref' => 'pi_test_1']);
    }

    if ($intent === null) {
        $this->http->reply('GET /v1/payment_intents/pi_test_1', ['error' => ['type' => 'api_error', 'message' => 'boom']], 500);
    } else {
        // Read twice: once to confirm, once more to release it.
        $this->http->reply('GET /v1/payment_intents/pi_test_1', $intent)->reply('GET /v1/payment_intents/pi_test_1', $intent);
    }

    $this->http->reply('POST /v1/payment_intents/pi_test_1/cancel', ['id' => 'pi_test_1', 'object' => 'payment_intent', 'status' => 'canceled']);

    $this->actingAs($b['parent'])->postJson(route('payments.checkout-intent.confirm'), $form)
        ->assertOk()
        ->assertExactJson(['confirmed' => false]);

    // The page tells the parent to start again, which places a new hold: the parent's own unusable one is released
    // (never another parent's, a spent or a saved-card intent, nor when Stripe cannot be asked).
    $released = ! in_array($case, ['another parent\'s hold', 'a hold the bank is still checking', 'a spent intent', 'a cancelled intent', 'a saved-card charge', 'Stripe not answering'], true);
    expect($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/cancel'))->toHaveCount($released ? 1 : 0)
        ->and($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/capture'))->toBeEmpty();
})->with([
    'another parent\'s hold', 'another tutor', 'another start time', 'a smaller amount', 'a larger amount', 'an amount only partly held',
    'a hold not yet confirmed', 'a hold the bank is still checking', 'a spent intent', 'a cancelled intent', 'automatic capture',
    'a live-mode intent on a test row', 'another currency', 'a saved-card charge', 'an intent that already became a payment',
    'an unknown learner', 'Stripe not answering',
]);

it('does not confirm a hold for someone who is not the parent it was made for, nor when the tutor or price no longer stands', function (string $case) {
    $b = scBooking();
    $this->http->reply('GET /v1/payment_intents/pi_test_1', scHeld($b))->reply('GET /v1/payment_intents/pi_test_1', scHeld($b));
    $user = $b['parent'];

    if ($case === 'a stranger') {
        $user = User::factory()->create();
    } elseif ($case === 'a tutor who stopped being bookable') {
        $b['tutor']->forceFill(['status' => 'suspended'])->save();
    } elseif ($case === 'a price changed since') {
        $b['tutor']->update(['hourly_rate' => 12000]);
    }

    $this->http->reply('POST /v1/payment_intents/pi_test_1/cancel', ['id' => 'pi_test_1', 'object' => 'payment_intent', 'status' => 'canceled']);

    $this->actingAs($user)->postJson(route('payments.checkout-intent.confirm'), scConfirmForm($b))
        ->assertOk()
        ->assertExactJson(['confirmed' => false]);

    // The parent's own hold is released; a stranger's call can never cancel someone else's.
    expect($this->http->requestsTo('POST /v1/payment_intents/pi_test_1/cancel'))->toHaveCount($case === 'a stranger' ? 0 : 1);
})->with(['a stranger', 'a tutor who stopped being bookable', 'a price changed since']);

it('keeps the bank\'s return address (with the client secret) out of the session\'s previous url', function () {
    $b = scBooking();
    $this->http->reply('GET /v1/payment_intents/pi_test_1', scHeld($b));

    $this->actingAs($b['parent'])
        ->withSession(['_previous' => ['url' => url('/tutors/1/book?payment_intent=pi_test_1&payment_intent_client_secret=pi_test_1_secret_x&redirect_status=succeeded')]])
        ->postJson(route('payments.checkout-intent.confirm'), scConfirmForm($b))
        ->assertOk();

    $previous = session('_previous.url');

    expect($previous)->toBe(route('tutors.book', ['tutor' => $b['tutor']->id, 'starts_at' => SC_SLOT]))
        ->and($previous)->not->toContain('secret');
});

it('refuses a malformed confirmation, a guest, and the fake driver', function () {
    $b = scBooking();

    $this->postJson(route('payments.checkout-intent.confirm'), scConfirmForm($b))->assertUnauthorized();

    foreach ([['payment_intent_id' => 'not-an-intent'], ['payment_intent_id' => 'pi_x/../y'], ['tutor_id' => 'abc'], ['starts_at' => 'tomorrow']] as $bad) {
        $this->actingAs($b['parent'])->postJson(route('payments.checkout-intent.confirm'), scConfirmForm($b, $bad))->assertUnprocessable();
    }

    expect($this->http->requests)->toBeEmpty();

    PaymentGatewayAccount::factory()->fake()->active()->create();

    $this->actingAs($b['parent'])->postJson(route('payments.checkout-intent.confirm'), scConfirmForm($b))->assertNotFound();
    expect($this->http->requests)->toBeEmpty();
});

it('keeps source tripwires on the booking page (no JS test runner exists: the behaviour itself is checked by hand on rehearsal, see STATUS)', function () {
    $page = file_get_contents(resource_path('js/pages/lessons/Book.vue'));

    // One Payment Element: the setup is started once and later amount changes wait for it.
    expect(substr_count($page, 'paymentElement.mount('))->toBe(1)
        ->and($page)->toContain('cardSetup === null')
        // Learner and subject cannot change while a payment is in flight or a hold is fixed.
        ->and(substr_count($page, ':disabled="locked"'))->toBe(2)
        // The redirect return is read and acted on, not only `redirect: 'if_required'`.
        ->and($page)->toContain("redirect: 'if_required'")
        ->and($page)->toContain('resumeAfterRedirect')
        ->and($page)->toContain('retrievePaymentIntent')
        // A failed Stripe.js load or a network error must not leave the form stuck.
        ->and($page)->toContain('startPayment(')
        ->and($page)->toContain('stripePromise = null')
        // R269: the secret leaves the address and Inertia's history entry first, and "approved" waits on the server.
        ->and($page)->toContain('router.replace(')
        ->and($page)->toContain('window.history.replaceState(')
        ->and($page)->toContain('/payments/checkout-intent/confirm')
        // The in-page decline forgets what it remembered for that payment.
        ->and(substr_count($page, 'forgetChoice(intent.body.payment_intent_id)'))->toBe(1);
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
