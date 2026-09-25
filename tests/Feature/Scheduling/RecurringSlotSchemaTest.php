<?php

use App\Enums\LessonCancelReason;
use App\Enums\LessonStatus;
use App\Enums\PaymentMethodStatus;
use App\Enums\RecurringSlotSkipReason;
use App\Enums\RecurringSlotStatus;
use App\Models\Lesson;
use App\Models\PaymentMethod;
use App\Models\RecurringSlot;
use App\Models\RecurringSlotSkip;
use App\Models\TutorProfile;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The CP4 (4a) schema: `recurring_slots` per DATA_MODEL v1.5, the live-slot unique index, the
 * `lessons (recurring_slot_id, starts_at)` idempotency key (with its R98/R99 deviation),
 * `recurring_slot_skips` and `payment_methods`. Each constraint is proved at the database with
 * the savepoint pattern of `LessonSchemaTest`.
 */
function rssIndexDefinition(string $index): string
{
    return DB::selectOne('select pg_get_indexdef(indexrelid) as def from pg_index join pg_class on pg_class.oid = indexrelid where pg_class.relname = ?', [$index])->def;
}

it('has every DATA_MODEL v1.5 column on recurring_slots and stores the price as integer fils', function () {
    foreach ([
        'id', 'learner_id', 'tutor_profile_id', 'curriculum_id', 'subject_id', 'weekday', 'start_time', 'timezone',
        'starts_on', 'ends_on', 'price', 'status', 'paused_reason', 'ended_by_user_id', 'ended_at', 'end_effective_on',
        'consecutive_charge_failures', 'generated_until', 'created_by_user_id', 'created_at', 'updated_at',
    ] as $column) {
        expect(Schema::hasColumn('recurring_slots', $column))->toBeTrue("recurring_slots.{$column}");
    }

    $slot = RecurringSlot::factory()->create(['price' => 12345])->fresh();

    expect($slot->price)->toBeInstanceOf(Money::class)
        ->and($slot->price->toFils())->toBe(12345)
        ->and($slot->consecutive_charge_failures)->toBe(0);

    foreach (['learner_id', 'curriculum_id', 'subject_id', 'price', 'generated_until', 'created_by_user_id'] as $column) {
        expect(fn () => DB::transaction(fn () => RecurringSlot::factory()->create([$column => null])))->toThrow(QueryException::class);
    }
});

it('refuses a second active weekly slot on the same tutor weekday, time and timezone', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    RecurringSlot::factory()->create(['tutor_profile_id' => $tutor->id]);

    expect(fn () => DB::transaction(fn () => RecurringSlot::factory()->create(['tutor_profile_id' => $tutor->id])))
        ->toThrow(QueryException::class, 'recurring_slots_live_unique');
});

it('keeps a paused slot in the live index, so resume can never collide (R99)', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    RecurringSlot::factory()->paused()->create(['tutor_profile_id' => $tutor->id]);

    expect(fn () => DB::transaction(fn () => RecurringSlot::factory()->create(['tutor_profile_id' => $tutor->id])))
        ->toThrow(QueryException::class, 'recurring_slots_live_unique');
});

it('frees the weekday and time when a slot is ended, and lets other times and tutors coexist', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    RecurringSlot::factory()->ended()->create(['tutor_profile_id' => $tutor->id]);

    RecurringSlot::factory()->create(['tutor_profile_id' => $tutor->id]);
    RecurringSlot::factory()->create(['tutor_profile_id' => $tutor->id, 'start_time' => '18:00']);
    RecurringSlot::factory()->create(['tutor_profile_id' => $tutor->id, 'weekday' => 3]);
    RecurringSlot::factory()->create();

    expect(RecurringSlot::query()->count())->toBe(5)
        ->and(rssIndexDefinition('recurring_slots_live_unique'))->toContain("'active'")->toContain("'paused'")->not->toContain("'ended'");
});

it('no longer has the CP2 active-only index', function () {
    $exists = DB::selectOne("select count(*) as n from pg_class where relname = 'recurring_slots_active_unique'")->n;

    expect((int) $exists)->toBe(0);
});

it('refuses two live lessons from the same slot at the same start, on different tutors so only the slot key can catch it', function () {
    $slot = RecurringSlot::factory()->create();
    $start = Carbon::parse('2026-10-06 13:00:00', 'UTC');

    Lesson::factory()->startingAt($start)->create(['recurring_slot_id' => $slot->id, 'status' => LessonStatus::Reserved]);

    expect(fn () => DB::transaction(fn () => Lesson::factory()->startingAt($start)->create(['recurring_slot_id' => $slot->id, 'status' => LessonStatus::Reserved])))
        ->toThrow(QueryException::class, 'lessons_recurring_slot_starts_at_unique');
});

it('lets the same slot generate a different start, another slot the same start, and lessons with no slot repeat freely', function () {
    $slot = RecurringSlot::factory()->create();
    $start = Carbon::parse('2026-10-06 13:00:00', 'UTC');

    Lesson::factory()->startingAt($start)->create(['recurring_slot_id' => $slot->id, 'status' => LessonStatus::Reserved]);
    Lesson::factory()->startingAt($start->copy()->addWeek())->create(['recurring_slot_id' => $slot->id, 'status' => LessonStatus::Reserved]);
    Lesson::factory()->startingAt($start)->create(['recurring_slot_id' => RecurringSlot::factory()->create()->id, 'status' => LessonStatus::Reserved]);
    Lesson::factory()->startingAt($start)->create(['status' => LessonStatus::Reserved]);
    Lesson::factory()->startingAt($start)->create(['status' => LessonStatus::Reserved]);

    expect(Lesson::query()->count())->toBe(5);
});

it('keeps a parent-skipped occurrence\'s key, so the next generation run cannot recreate it (invariant 14)', function () {
    $slot = RecurringSlot::factory()->create();
    $start = Carbon::parse('2026-10-06 13:00:00', 'UTC');

    Lesson::factory()->startingAt($start)->create([
        'recurring_slot_id' => $slot->id,
        'status' => LessonStatus::CancelledByParent,
        'cancel_reason' => 'Away that week',
    ]);
    Lesson::factory()->startingAt($start->copy()->addWeek())->create([
        'recurring_slot_id' => $slot->id,
        'status' => LessonStatus::CancelledByParent,
        'cancel_reason' => null,
    ]);

    foreach ([$start, $start->copy()->addWeek()] as $regenerated) {
        expect(fn () => DB::transaction(fn () => Lesson::factory()->startingAt($regenerated)->create(['recurring_slot_id' => $slot->id, 'status' => LessonStatus::Reserved])))
            ->toThrow(QueryException::class, 'lessons_recurring_slot_starts_at_unique');
    }
});

it('frees the key of a pause-cancelled occurrence, so resume can regenerate that week (R99)', function () {
    $slot = RecurringSlot::factory()->create();
    $start = Carbon::parse('2026-10-06 13:00:00', 'UTC');

    Lesson::factory()->startingAt($start)->create([
        'recurring_slot_id' => $slot->id,
        'status' => LessonStatus::CancelledByParent,
        'cancel_reason' => LessonCancelReason::SlotPaused->value,
    ]);

    $regenerated = Lesson::factory()->startingAt($start)->create(['recurring_slot_id' => $slot->id, 'status' => LessonStatus::Reserved]);

    // A second pause cycle cancels the regenerated row too: pause-cancelled rows may repeat.
    Lesson::query()->whereKey($regenerated->id)->update(['status' => LessonStatus::CancelledByParent->value, 'cancel_reason' => LessonCancelReason::SlotPaused->value]);
    Lesson::factory()->startingAt($start)->create(['recurring_slot_id' => $slot->id, 'status' => LessonStatus::Reserved]);

    expect(Lesson::query()->where('recurring_slot_id', $slot->id)->count())->toBe(3);
});

it('bakes the paused reason into the lessons key from the enum', function () {
    $definition = rssIndexDefinition('lessons_recurring_slot_starts_at_unique');

    expect($definition)->toContain('UNIQUE')
        ->and($definition)->toContain('recurring_slot_id, starts_at')
        ->and($definition)->toContain("'".LessonCancelReason::SlotPaused->value."'")
        ->and($definition)->toContain('IS DISTINCT FROM');
});

it('records a skipped occurrence once per slot and start, with its reason', function () {
    $slot = RecurringSlot::factory()->create();
    $start = Carbon::parse('2026-10-06 13:00:00', 'UTC');

    $skip = RecurringSlotSkip::query()->create(['recurring_slot_id' => $slot->id, 'starts_at' => $start, 'reason' => RecurringSlotSkipReason::TutorBlocked])->fresh();

    expect($skip->reason)->toBe(RecurringSlotSkipReason::TutorBlocked)
        ->and($skip->notified_at)->toBeNull()
        ->and($slot->skips()->count())->toBe(1)
        ->and(fn () => DB::transaction(fn () => RecurringSlotSkip::query()->create(['recurring_slot_id' => $slot->id, 'starts_at' => $start, 'reason' => RecurringSlotSkipReason::LessonCollision])))
        ->toThrow(QueryException::class, 'recurring_slot_skips_recurring_slot_id_starts_at_unique');

    RecurringSlotSkip::query()->create(['recurring_slot_id' => $slot->id, 'starts_at' => $start->copy()->addWeek(), 'reason' => RecurringSlotSkipReason::LessonCollision]);
    expect(RecurringSlotSkip::query()->count())->toBe(2);
});

it('refuses an unknown skip reason at the database', function () {
    $slot = RecurringSlot::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('recurring_slot_skips')->insert(['recurring_slot_id' => $slot->id, 'starts_at' => now(), 'reason' => 'because', 'created_at' => now(), 'updated_at' => now()])))
        ->toThrow(QueryException::class);
});

it('stores a saved card as a token, brand, last four and expiry only, one per account', function () {
    $columns = Schema::getColumnListing('payment_methods');

    expect($columns)->toEqualCanonicalizing([
        'id', 'account_user_id', 'gateway', 'gateway_customer_ref', 'gateway_token', 'brand', 'last4', 'exp_month', 'exp_year',
        'status', 'last_failed_at', 'created_at', 'updated_at',
    ]);

    $card = PaymentMethod::factory()->create();

    expect($card->fresh()->status)->toBe(PaymentMethodStatus::Active)
        ->and($card->account->paymentMethod->is($card))->toBeTrue()
        ->and($card->toArray())->not->toHaveKey('gateway_token')
        ->and(fn () => DB::transaction(fn () => PaymentMethod::factory()->create(['account_user_id' => $card->account_user_id])))
        ->toThrow(QueryException::class, 'payment_methods_account_user_id_unique');
});

it('keys lessons.payment_method_id to payment_methods', function () {
    $card = PaymentMethod::factory()->create();

    expect(Lesson::factory()->create(['payment_method_id' => $card->id])->paymentMethod->is($card))->toBeTrue()
        ->and(fn () => DB::transaction(fn () => Lesson::factory()->create(['payment_method_id' => $card->id + 999])))
        ->toThrow(QueryException::class, 'lessons_payment_method_id_foreign')
        ->and(fn () => DB::transaction(fn () => DB::table('payment_methods')->where('id', $card->id)->delete()))
        ->toThrow(QueryException::class);
});

it('reads a slot\'s effective end as the earlier of ends_on and end_effective_on', function () {
    expect(RecurringSlot::factory()->make(['ends_on' => null, 'end_effective_on' => null])->effectiveEndDate())->toBeNull()
        ->and(RecurringSlot::factory()->make(['ends_on' => '2026-10-20', 'end_effective_on' => null])->effectiveEndDate()->toDateString())->toBe('2026-10-20')
        ->and(RecurringSlot::factory()->make(['ends_on' => null, 'end_effective_on' => '2026-10-13'])->effectiveEndDate()->toDateString())->toBe('2026-10-13')
        ->and(RecurringSlot::factory()->make(['ends_on' => '2026-10-20', 'end_effective_on' => '2026-10-13'])->effectiveEndDate()->toDateString())->toBe('2026-10-13')
        ->and(RecurringSlot::factory()->make(['ends_on' => '2026-10-06', 'end_effective_on' => '2026-10-13'])->effectiveEndDate()->toDateString())->toBe('2026-10-06');
});

it('exposes the holding statuses as active and paused only', function () {
    expect(RecurringSlot::holdingStatuses())->toBe([RecurringSlotStatus::Active, RecurringSlotStatus::Paused]);
});
