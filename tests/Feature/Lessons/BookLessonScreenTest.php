<?php

use App\Enums\CurriculumCode;
use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\LevelTier;
use App\Enums\TutorProfileStatus;
use App\Exceptions\PaymentCaptureException;
use App\Mail\Lessons\LessonConfirmedMail;
use App\Models\AvailabilityRule;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\PaymentMethod;
use App\Models\PriceBand;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Providers\PaymentGatewayServiceProvider;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\PaymentCaptureResult;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\SavedCard;
use App\Support\Facades\Settings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;

/**
 * CP3 (6a, R114): the parent's single-booking screen. "Now" is Monday 2026-09-14 06:00 UTC; the
 * tutor is open on Tuesdays 09:00–12:00 UTC, so 2026-09-15 09:00/10:00/11:00 UTC are bookable.
 * `BookLesson` itself is covered by BookLessonTest; this file covers the screen around it.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC'));
});

const BK_SLOT = '2026-09-15T09:00:00Z';
const BK_SLOT_2 = '2026-09-15T10:00:00Z';

/**
 * @return array{tutor: TutorProfile, curriculum_id: int, subject_id: int}
 */
function bkTutor(int $rate = 10000): array
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

    // Tuesday for the happy path, Monday to give the "inside the 12h lead" case a real slot to refuse.
    foreach ([2, 1] as $weekday) {
        AvailabilityRule::factory()->create([
            'tutor_profile_id' => $tutor->id, 'weekday' => $weekday, 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'timezone' => 'UTC',
        ]);
    }

    return ['tutor' => $tutor->fresh(), 'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id];
}

/**
 * @return array{parent: User, learner: Learner}
 */
function bkParent(string $timezone = 'UTC'): array
{
    $parent = User::factory()->create(['timezone' => $timezone]);
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => gcseCurriculumId()]);

    return ['parent' => $parent, 'learner' => $learner];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function bkForm(array $setup, Learner $learner, string $startsAt = BK_SLOT, array $extra = []): array
{
    $extra += ['quote_token' => bkQuoteToken($setup['tutor'], $learner, $startsAt)];

    return array_merge([
        'learner_id' => $learner->id,
        'tutor_id' => $setup['tutor']->id,
        'curriculum_id' => $setup['curriculum_id'],
        'subject_id' => $setup['subject_id'],
        'starts_at' => $startsAt,
    ], $extra);
}

/**
 * The token the page carries for this learner, read the way the browser does: from the rendered
 * props of the owner's own GET. 'none' when the page shows no such learner or tutor.
 */
function bkQuoteToken(TutorProfile $tutor, Learner $learner, string $startsAt = BK_SLOT): string
{
    $owner = User::query()->find($learner->account_user_id);

    if ($owner === null) {
        return 'none';
    }

    // Read as the owner, then put the caller's authentication back as it was.
    $previous = auth()->user();
    $response = test()->actingAs($owner)->get(bkUrl($tutor, $startsAt));

    if ($previous !== null) {
        test()->actingAs($previous);
    } else {
        app('auth')->forgetGuards();
    }

    if ($response->status() !== 200) {
        return 'none';
    }

    $row = collect($response->inertiaPage()['props']['learners'] ?? [])->firstWhere('id', $learner->id);

    return $row['quote_token'] ?? 'none';
}

function bkUrl(TutorProfile $tutor, string $startsAt = BK_SLOT): string
{
    return route('tutors.book', $tutor->id).'?starts_at='.rawurlencode($startsAt);
}

function bkCurrency(): string
{
    return (string) Settings::get('currency_code');
}

function bkBootAs(string $env): void
{
    app()->offsetUnset(PaymentGateway::class);
    app()['env'] = $env;
    config(['app.env' => $env]);
    (new PaymentGatewayServiceProvider(app()))->register();
}

function bkDecliningGateway(): void
{
    app()->instance(PaymentGateway::class, new class implements PaymentGateway
    {
        public function driver(): string
        {
            return 'fake';
        }

        public function saveCard(User $account, string $selection): SavedCard
        {
            throw new LogicException('not used');
        }

        public function chargeSavedCard(Lesson $lesson, Money $amount, PaymentMethod $method, string $idempotencyKey): PaymentCaptureResult
        {
            throw new LogicException('not used');
        }

        public function capture(Lesson $lesson, Money $amount, string $idempotencyKey): PaymentCaptureResult
        {
            throw new PaymentCaptureException('card_declined');
        }
    });
}

afterEach(function () {
    bkBootAs('testing');
});

// --- Entry, access and return -------------------------------------------------------------------

it('sends a guest to sign in and, after a real login, back to the same booking URL with its slot intact', function () {
    $setup = bkTutor();
    ['parent' => $parent] = bkParent();
    $url = bkUrl($setup['tutor']);

    $this->get($url)->assertRedirect(route('login'));

    $response = $this->post(route('login'), ['email' => $parent->email, 'password' => 'password']);

    $location = $response->headers->get('Location');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect(parse_url((string) $location, PHP_URL_PATH))->toBe("/tutors/{$setup['tutor']->id}/book")
        ->and($query['starts_at'])->toBe(BK_SLOT);

    $this->get($location)->assertOk()->assertInertia(fn ($page) => $page->component('lessons/Book')->where('starts_at', BK_SLOT));
});

it('redirects a guest posting a booking to sign in and books nothing', function () {
    $setup = bkTutor();
    ['learner' => $learner] = bkParent();

    $this->post(route('lessons.store'), bkForm($setup, $learner))->assertRedirect(route('login'));

    expect(Lesson::query()->count())->toBe(0);
});

it('refuses a tutor and an admin on both the page and the booking', function (string $state) {
    $setup = bkTutor();
    ['learner' => $learner] = bkParent();
    $user = User::factory()->{$state}()->create();

    $this->actingAs($user)->get(bkUrl($setup['tutor']))->assertForbidden();
    $this->actingAs($user)->post(route('lessons.store'), bkForm($setup, $learner))->assertForbidden();

    expect(Lesson::query()->count())->toBe(0);
})->with(['tutor', 'admin']);

it('gives slot links to guests and parents only, through the props the profile page reads', function () {
    $setup = bkTutor();
    ['parent' => $parent] = bkParent();

    $this->get(route('tutors.show', $setup['tutor']->id))
        ->assertInertia(fn ($page) => $page->where('auth.user', null)->where('can_set_up_weekly', false));

    $this->actingAs($parent)->get(route('tutors.show', $setup['tutor']->id))
        ->assertInertia(fn ($page) => $page->where('can_set_up_weekly', true)->has('auth.user'));

    foreach (['tutor', 'admin'] as $state) {
        $this->actingAs(User::factory()->{$state}()->create())->get(route('tutors.show', $setup['tutor']->id))
            ->assertInertia(fn ($page) => $page->where('can_set_up_weekly', false)->has('auth.user'));
    }

    $component = file_get_contents(resource_path('js/pages/tutors/Show.vue'));
    expect($component)
        ->toContain('!page.props.auth?.user || props.can_set_up_weekly')
        ->toContain('encodeURIComponent(utc)')
        ->toContain("replace(/\\.\\d{3}Z\$/, 'Z')");
});

it('returns to the tutor profile for an unparseable slot and 404s an unbookable tutor', function (string $startsAt) {
    $setup = bkTutor();
    ['parent' => $parent] = bkParent();

    $this->actingAs($parent)->get(route('tutors.book', $setup['tutor']->id).'?starts_at='.rawurlencode($startsAt))
        ->assertRedirect(route('tutors.show', $setup['tutor']->id));
})->with(['tomorrow', '2026-13-45T09:00:00Z', '2026-09-15T09:00:00+04:00', '2026-09-15 09:00:00', '']);

it('shows no page for a tutor who is not bookable', function () {
    $setup = bkTutor();
    ['parent' => $parent] = bkParent();
    TutorProfile::query()->whereKey($setup['tutor']->id)->update(['status' => TutorProfileStatus::Suspended]);

    $this->actingAs($parent)->get(bkUrl($setup['tutor']))->assertNotFound();
});

it('shows the slot in the parent\'s timezone, marks a taken slot and lists other times', function () {
    $setup = bkTutor();
    ['parent' => $parent] = bkParent('Asia/Dubai');

    $this->actingAs($parent)->get(bkUrl($setup['tutor']))->assertOk()->assertInertia(fn ($page) => $page
        ->where('slot_label', 'Tue 15 Sep 2026, 13:00')
        ->where('timezone', 'Asia/Dubai')
        ->where('slot_available', true)
        ->where('starts_at', BK_SLOT)
        ->has('alternatives', fn ($alternatives) => $alternatives->etc())
        ->where('alternatives.0.starts_at', BK_SLOT)
        ->where('alternatives.0.label', 'Tue 15 Sep, 13:00'));

    $this->actingAs($parent)->get(bkUrl($setup['tutor'], '2026-09-15T09:30:00Z'))
        ->assertInertia(fn ($page) => $page->where('slot_available', false));
});

it('lists only the parent\'s own, not deleted, learners and none for a parent with none', function () {
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $mine] = bkParent();
    ['learner' => $theirs] = bkParent();
    $deleted = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => gcseCurriculumId()]);
    $deleted->delete();

    $this->actingAs($parent)->get(bkUrl($setup['tutor']))->assertInertia(fn ($page) => $page
        ->has('learners', 1)
        ->where('learners.0.id', $mine->id)
        ->where('selected_learner', $mine->id));

    $lonely = User::factory()->create();
    $this->actingAs($lonely)->get(bkUrl($setup['tutor']))->assertInertia(fn ($page) => $page->has('learners', 0)->where('selected_learner', null));
});

// --- Booking -------------------------------------------------------------------------------------

it('books a first lesson as a discounted trial: confirmed, held, ledger at zero, redirected with a toast', function () {
    Mail::fake();
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner))
        ->assertRedirect(route('learners.show', $learner))
        ->assertSessionHasNoErrors();

    $lesson = Lesson::query()->sole();

    expect($lesson->type)->toBe(LessonType::Trial)
        ->and($lesson->status)->toBe(LessonStatus::Confirmed)
        ->and($lesson->price->equals($setup['tutor']->trialPrice()))->toBeTrue()
        ->and($lesson->price->toFils())->toBeLessThan(10000)
        ->and($lesson->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-09-15 09:00')
        ->and($lesson->booked_by_user_id)->toBe($parent->id)
        ->and(LedgerEntry::query()->where('lesson_id', $lesson->id)->where('account', LedgerAccount::Escrow)->where('type', LedgerEntryType::Hold)->exists())->toBeTrue()
        ->and(app(LedgerService::class)->sum($lesson))->toBe(0);
});

it('books a second lesson with the same tutor as a regular lesson at the full rate', function () {
    Mail::fake();
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner))->assertSessionHasNoErrors();
    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, BK_SLOT_2))->assertSessionHasNoErrors();

    $second = Lesson::query()->orderByDesc('starts_at')->first();

    expect(Lesson::query()->count())->toBe(2)
        ->and($second->type)->toBe(LessonType::Regular)
        ->and($second->price->toFils())->toBe(10000)
        ->and(app(LedgerService::class)->sum($second))->toBe(0);
});

it('queues the confirmed email to both the parent and the tutor', function () {
    Mail::fake();
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner))->assertSessionHasNoErrors();

    Mail::assertQueued(LessonConfirmedMail::class, 2);
    Mail::assertQueued(LessonConfirmedMail::class, fn ($mail) => $mail->hasTo($parent->email));
    Mail::assertQueued(LessonConfirmedMail::class, fn ($mail) => $mail->hasTo($setup['tutor']->user->email));
});

it('ignores a tampered type, price or policy field and freezes what BookLesson computes', function () {
    Mail::fake();
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, BK_SLOT, [
        'type' => 'regular',
        'price' => 1,
        'amount' => 1,
        'commission_pct' => 0,
        'commission_amount' => 0,
        'tutor_amount' => 10000,
        'status' => 'settled',
        'cancel_window_hours' => 0,
    ]))->assertSessionHasNoErrors();

    $lesson = Lesson::query()->sole();

    expect($lesson->type)->toBe(LessonType::Trial)
        ->and($lesson->status)->toBe(LessonStatus::Confirmed)
        ->and($lesson->price->equals($setup['tutor']->trialPrice()))->toBeTrue()
        ->and($lesson->commission_pct)->toBe((int) Settings::get('commission_pct'))
        ->and($lesson->cancel_window_hours)->toBe((int) Settings::get('cancel_window_hours'));
});

// --- What the page shows is what is charged (R30: every dependency of the displayed price) ----------

it('shows the trial price and the regular price exactly as the action will freeze them', function () {
    Mail::fake();
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();
    $currency = bkCurrency();

    $this->actingAs($parent)->get(bkUrl($setup['tutor']))->assertInertia(fn ($page) => $page
        ->where('learners.0.type', 'trial')
        ->where('learners.0.type_label', 'Trial lesson')
        ->where('learners.0.price', $setup['tutor']->trialPrice()->format($currency)));

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner))->assertSessionHasNoErrors();
    $trial = Lesson::query()->sole();

    // A trial that just succeeded turns the next visit into a regular lesson.
    $this->actingAs($parent)->get(bkUrl($setup['tutor'], BK_SLOT_2))->assertInertia(fn ($page) => $page
        ->where('learners.0.type', 'regular')
        ->where('learners.0.type_label', 'Lesson')
        ->where('learners.0.price', $setup['tutor']->hourly_rate->format($currency)));

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, BK_SLOT_2))->assertSessionHasNoErrors();
    $regular = Lesson::query()->whereKeyNot($trial->id)->sole();

    expect($regular->price->format($currency))->toBe($setup['tutor']->hourly_rate->format($currency))
        ->and($trial->price->format($currency))->toBe($setup['tutor']->trialPrice()->format($currency));
});

it('shows a trial again once the only booking with the tutor has been cancelled', function () {
    Mail::fake();
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner))->assertSessionHasNoErrors();
    $lesson = Lesson::query()->sole();

    $this->actingAs($parent)->get(bkUrl($setup['tutor'], BK_SLOT_2))->assertInertia(fn ($page) => $page->where('learners.0.type', 'regular'));

    Lesson::query()->whereKey($lesson->id)->update(['status' => LessonStatus::CancelledByParent]);

    $this->actingAs($parent)->get(bkUrl($setup['tutor'], BK_SLOT_2))->assertInertia(fn ($page) => $page->where('learners.0.type', 'trial'));

    // ...and the action agrees: the next booking really is a trial.
    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, BK_SLOT_2))->assertSessionHasNoErrors();
    expect(Lesson::query()->whereKeyNot($lesson->id)->sole()->type)->toBe(LessonType::Trial);
});

it('derives type, price and the subject preselect per learner, so changing the learner changes them', function () {
    Mail::fake();
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $first] = bkParent();
    $other = Curriculum::factory()->create(['code' => CurriculumCode::Cbse]);
    $second = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => $other->id, 'display_name' => 'Zed']);
    $first->update(['display_name' => 'Abe']);

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $first))->assertSessionHasNoErrors();

    $this->actingAs($parent)->get(bkUrl($setup['tutor'], BK_SLOT_2))->assertInertia(fn ($page) => $page
        ->where('selected_learner', null)
        ->where('learners.0.display_name', 'Abe')
        ->where('learners.0.type', 'regular')
        ->where('learners.0.preselect.subject_id', $setup['subject_id'])
        ->where('learners.1.display_name', 'Zed')
        ->where('learners.1.type', 'trial')
        ->where('learners.1.preselect', null));
});

// --- Refusals and errors: nothing is created --------------------------------------------------------

it('refuses another parent\'s learner and a deleted learner with one generic message and creates nothing', function () {
    $setup = bkTutor();
    ['parent' => $parent] = bkParent();
    ['learner' => $theirs] = bkParent();
    $deleted = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => gcseCurriculumId()]);
    $deleted->delete();

    foreach ([$theirs, $deleted] as $learner) {
        $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner))
            ->assertSessionHasErrors(['slot' => 'That lesson cannot be booked.']);
    }

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $theirs, BK_SLOT, ['learner_id' => 999999]))
        ->assertSessionHasErrors(['slot' => 'That lesson cannot be booked.']);

    expect(Lesson::query()->count())->toBe(0);
});

it('refuses an unbookable tutor with an error and creates nothing', function () {
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();
    TutorProfile::query()->whereKey($setup['tutor']->id)->update(['status' => TutorProfileStatus::Suspended]);

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner))
        ->assertSessionHasErrors(['slot' => 'That lesson cannot be booked.']);

    expect(Lesson::query()->count())->toBe(0);
});

it('fails a subject the tutor does not teach, through the action', function () {
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, BK_SLOT, ['subject_id' => Subject::factory()->create()->id]))
        ->assertSessionHasErrors('slot');

    expect(Lesson::query()->count())->toBe(0);
});

it('fails a slot outside the booking rules through the action and creates nothing', function (string $startsAt) {
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, $startsAt))->assertSessionHasErrors('slot');

    expect(Lesson::query()->count())->toBe(0);
})->with([
    'inside the 12h lead' => '2026-09-14T09:00:00Z',
    'beyond 30 days' => '2026-11-10T09:00:00Z',
    'off the hour' => '2026-09-15T09:30:00Z',
    'outside availability' => '2026-09-15T15:00:00Z',
    'in the past' => '2026-09-08T09:00:00Z',
]);

it('rejects a malformed starts_at as a validation error before anything runs', function (string $startsAt) {
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, $startsAt))->assertSessionHasErrors('starts_at');

    expect(Lesson::query()->count())->toBe(0);
})->with(['tomorrow', '2026-09-15 09:00:00', '2026-09-15T09:00:00+04:00', '2026-13-15T09:00:00Z', '']);

it('refuses a slot that has just been taken, returns to the page and refreshes the slot list', function () {
    Mail::fake();
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();
    ['parent' => $rival, 'learner' => $rivalLearner] = bkParent();

    $url = bkUrl($setup['tutor']);
    $this->actingAs($parent)->get($url)->assertInertia(fn ($page) => $page->where('slot_available', true));

    $this->actingAs($rival)->post(route('lessons.store'), bkForm($setup, $rivalLearner))->assertSessionHasNoErrors();

    $this->actingAs($parent)->from($url)->post(route('lessons.store'), bkForm($setup, $learner))
        ->assertRedirect($url)
        ->assertSessionHasErrors('slot');

    $this->actingAs($parent)->get($url)->assertInertia(fn ($page) => $page
        ->where('slot_available', false)
        ->where('alternatives.0.starts_at', BK_SLOT_2));

    expect(Lesson::query()->count())->toBe(1);
});

it('says the payment did not go through when the gateway declines, and leaves no confirmed lesson', function () {
    Mail::fake();
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();
    bkDecliningGateway();

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner))
        ->assertSessionHasErrors(['slot' => 'Payment did not go through. Nothing was booked.']);

    expect(Lesson::query()->where('status', LessonStatus::Confirmed)->count())->toBe(0)
        ->and(Lesson::query()->sole()->status)->toBe(LessonStatus::Expired);
    Mail::assertNotQueued(LessonConfirmedMail::class);
});

// --- Gateway binding (R107) -------------------------------------------------------------------------

it('shows the test-mode flag where the fake gateway is bound (not on production), and refuses booking plainly when no gateway is bound', function () {
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    $this->actingAs($parent)->get(bkUrl($setup['tutor']))->assertInertia(fn ($page) => $page->where('paymentTestMode', true)->where('can_pay', true));

    bkBootAs('production');

    $this->actingAs($parent)->get(bkUrl($setup['tutor']))->assertInertia(fn ($page) => $page->where('paymentTestMode', false)->where('can_pay', false));

    // The POST half tests the unbound-gateway guard in store(): the gateway binding is removed but the environment is put back to testing, so CSRF handling stays as in every other test.
    bkBootAs('testing');
    app()->offsetUnset(PaymentGateway::class);
    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner))
        ->assertSessionHasErrors(['slot' => 'Booking is not available yet.']);

    expect(Lesson::query()->count())->toBe(0)
        ->and(file_get_contents(resource_path('js/pages/lessons/Book.vue')))->toContain('<TestModeBanner')->toContain('Confirm and pay');
});

it('has no card form and never sends a type or price from the browser', function () {
    $page = file_get_contents(resource_path('js/pages/lessons/Book.vue'));
    $form = substr($page, (int) strpos($page, 'useForm({'), 260);

    expect($page)->not->toContain('card_number')->not->toContain('cvc')
        ->and($form)->toContain('learner_id')->toContain('starts_at')
        ->and($form)->not->toContain('type')->not->toContain('price')
        ->and($page)->toContain("post('/lessons')");
});

// --- Stale quote (review 1, item 10) ----------------------------------------------------------

it('refuses a confirm whose shown type or price is stale, because the trial was booked in another tab', function () {
    Mail::fake();
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    // Two tabs open on the same learner: both were drawn showing the discounted trial.
    $tabA = bkForm($setup, $learner, BK_SLOT);
    $tabB = bkForm($setup, $learner, BK_SLOT_2);

    $this->actingAs($parent)->post(route('lessons.store'), $tabA)->assertSessionHasNoErrors();
    expect(Lesson::query()->count())->toBe(1);

    $this->actingAs($parent)->post(route('lessons.store'), $tabB)
        ->assertSessionHasErrors(['slot' => 'The type or price of this lesson has changed. Please review it and confirm again.']);

    // Nothing was charged at the full rate; the next page shows the regular quote and books at it.
    expect(Lesson::query()->count())->toBe(1);

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, BK_SLOT_2))->assertSessionHasNoErrors();
    expect(Lesson::query()->orderByDesc('starts_at')->first()->price->toFils())->toBe(10000);
});

it('refuses a confirm when the tutor changed their rate or the trial discount after the page was drawn', function () {
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    $form = bkForm($setup, $learner);
    TutorProfile::query()->whereKey($setup['tutor']->id)->update(['hourly_rate' => 12000]);

    $this->actingAs($parent)->post(route('lessons.store'), $form)->assertSessionHasErrors('slot');
    expect(Lesson::query()->count())->toBe(0);

    $form = bkForm($setup, $learner);
    Settings::set('trial_discount_pct', 10);

    $this->actingAs($parent)->post(route('lessons.store'), $form)->assertSessionHasErrors('slot');
    expect(Lesson::query()->count())->toBe(0);
});

it('refuses a missing, garbage or another learner\'s token, and the token is not a readable type or price', function () {
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();
    ['learner' => $other] = bkParent();

    $this->actingAs($parent)->post(route('lessons.store'), Arr::except(bkForm($setup, $learner), 'quote_token'))
        ->assertSessionHasErrors('quote_token');
    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, BK_SLOT, ['quote_token' => 'garbage']))
        ->assertSessionHasErrors('slot');
    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, BK_SLOT, ['quote_token' => bkQuoteToken($setup['tutor'], $other)]))
        ->assertSessionHasErrors('slot');

    $token = bkQuoteToken($setup['tutor'], $learner);

    expect(Lesson::query()->count())->toBe(0)
        ->and($token)->toMatch('/^[0-9a-f]{64}$/')
        ->and($token)->not->toContain('trial')->not->toContain('10000');
});

it('gives the same refusal for a tutor that does not exist and one that is not bookable', function () {
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, BK_SLOT, ['tutor_id' => 999999]))
        ->assertSessionHasErrors(['slot' => 'That lesson cannot be booked.']);

    TutorProfile::query()->whereKey($setup['tutor']->id)->update(['status' => TutorProfileStatus::PendingReview]);

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner))
        ->assertSessionHasErrors(['slot' => 'That lesson cannot be booked.']);
});

it('flashes the confirmation toast after a booking', function () {
    Mail::fake();
    $setup = bkTutor();
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner))
        ->assertRedirect(route('learners.show', $learner))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Lesson confirmed.');
});

// --- R179: the tutor's own lead time and the within-24h notice --------------------------------------

it('books inside the old 12h default when the tutor chose a shorter lead, and refuses what is inside it', function (int $lead, string $startsAt, string $now, bool $accepted) {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
    $setup = bkTutor();
    TutorProfile::query()->whereKey($setup['tutor']->id)->update(['min_lead_hours' => $lead]);
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    $response = $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, $startsAt));

    if ($accepted) {
        $response->assertSessionHasNoErrors();
    } else {
        $response->assertSessionHasErrors('slot');
    }

    expect(Lesson::query()->count())->toBe($accepted ? 1 : 0);
})->with([
    // Monday 09:00 UTC slot, tutor open Monday 09:00-12:00. Lead 0: strictly in the future.
    'lead 0, one minute before the slot' => [0, '2026-09-14T09:00:00Z', '2026-09-14 08:59:00', true],
    'lead 0, at the slot start' => [0, '2026-09-14T09:00:00Z', '2026-09-14 09:00:00', false],
    // Lead 4: the slot must start at or after now + 4h, to the minute.
    'lead 4, exactly now + 4h' => [4, '2026-09-14T10:00:00Z', '2026-09-14 06:00:00', true],
    'lead 4, one minute short' => [4, '2026-09-14T10:00:00Z', '2026-09-14 06:01:00', false],
    'lead 4, three hours ahead' => [4, '2026-09-14T09:00:00Z', '2026-09-14 06:00:00', false],
    // Lead 24: Tuesday 09:00 UTC.
    'lead 24, exactly now + 24h' => [24, '2026-09-15T09:00:00Z', '2026-09-14 09:00:00', true],
    'lead 24, one minute short' => [24, '2026-09-15T09:00:00Z', '2026-09-14 09:01:00', false],
]);

it('applies the lead in the instant, whatever timezone the tutor keeps their hours in', function (int $lead, string $now, bool $accepted) {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
    $setup = bkTutor();
    // Tuesday 09:00 Asia/Dubai (UTC+4) = 05:00 UTC.
    AvailabilityRule::query()->where('tutor_profile_id', $setup['tutor']->id)->delete();
    AvailabilityRule::factory()->create([
        'tutor_profile_id' => $setup['tutor']->id, 'weekday' => 2, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'timezone' => 'Asia/Dubai',
    ]);
    TutorProfile::query()->whereKey($setup['tutor']->id)->update(['min_lead_hours' => $lead]);
    ['parent' => $parent, 'learner' => $learner] = bkParent('Asia/Dubai');

    $response = $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, '2026-09-15T05:00:00Z'));

    $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('slot');
    expect(Lesson::query()->count())->toBe($accepted ? 1 : 0);
})->with([
    'lead 4 at 01:00 UTC' => [4, '2026-09-15 01:00:00', true],
    'lead 4 at 01:01 UTC' => [4, '2026-09-15 01:01:00', false],
    'lead 0 at 04:59 UTC' => [0, '2026-09-15 04:59:00', true],
    'lead 0 at 05:00 UTC' => [0, '2026-09-15 05:00:00', false],
]);

it('refuses lead 0 when immediate booking is switched off, even for a tutor who chose it', function () {
    Mail::fake();
    Settings::set('allow_immediate_booking', false);
    $setup = bkTutor();
    TutorProfile::query()->whereKey($setup['tutor']->id)->update(['min_lead_hours' => 0]);
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    // Monday 09:00 is three hours ahead; the smallest allowed value (4) now applies.
    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, '2026-09-14T09:00:00Z'))->assertSessionHasErrors('slot');
    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, '2026-09-14T10:00:00Z'))->assertSessionHasNoErrors();

    expect(Lesson::query()->count())->toBe(1);
});

it('does not change an already booked lesson when the tutor later raises their lead time', function () {
    Mail::fake();
    $setup = bkTutor();
    TutorProfile::query()->whereKey($setup['tutor']->id)->update(['min_lead_hours' => 0]);
    ['parent' => $parent, 'learner' => $learner] = bkParent();

    $this->actingAs($parent)->post(route('lessons.store'), bkForm($setup, $learner, '2026-09-14T09:00:00Z'))->assertSessionHasNoErrors();
    $lesson = Lesson::query()->sole();

    TutorProfile::query()->whereKey($setup['tutor']->id)->update(['min_lead_hours' => 24]);
    Settings::set('booking_min_lead_hours', 48);

    $fresh = $lesson->fresh();
    expect($fresh->starts_at->equalTo($lesson->starts_at))->toBeTrue()
        ->and($fresh->status)->toBe(LessonStatus::Confirmed);
});

it('states before payment that the lesson cannot be cancelled for a refund, exactly when the cancel window applies', function (int $window, string $now, string $startsAt, bool $notice) {
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
    Settings::set('cancel_window_hours', $window);
    $setup = bkTutor();
    TutorProfile::query()->whereKey($setup['tutor']->id)->update(['min_lead_hours' => 0]);
    ['parent' => $parent] = bkParent();

    $this->actingAs($parent)->get(bkUrl($setup['tutor'], $startsAt))->assertInertia(fn ($page) => $notice
        ? $page->where('cancel_notice', "This lesson starts within {$window} hours, so it cannot be cancelled for a refund.")
        : $page->where('cancel_notice', null));
})->with([
    'starts in 3h' => [24, '2026-09-14 06:00:00', '2026-09-14T09:00:00Z', true],
    'starts in exactly 24h' => [24, '2026-09-14 09:00:00', '2026-09-15T09:00:00Z', true],
    'starts in 24h and one second' => [24, '2026-09-14 08:59:59', '2026-09-15T09:00:00Z', false],
    'starts in 27h' => [24, '2026-09-14 06:00:00', '2026-09-15T09:00:00Z', false],
    'a 12h window, starts in exactly 12h' => [12, '2026-09-14 21:00:00', '2026-09-15T09:00:00Z', true],
    'a 12h window, starts in 13h' => [12, '2026-09-14 20:00:00', '2026-09-15T09:00:00Z', false],
]);

it('shows the notice paragraph on the booking page and leaves the cancellation rule itself alone', function () {
    $page = file_get_contents(resource_path('js/pages/lessons/Book.vue'));

    expect($page)->toContain('data-testid="cancel-notice"')->toContain('slot_available && cancel_notice');
});
