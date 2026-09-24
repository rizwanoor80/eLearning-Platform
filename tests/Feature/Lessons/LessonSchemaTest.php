<?php

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The lessons table per DATA_MODEL, its two partial unique indexes (3b), and the
 * 3c `lessons_tutor_no_overlap` GiST exclusion constraint. The predicates are
 * baked into the indexes when the migration runs, so a test reads them back and
 * compares them with the enum — if `LessonStatus::freeingSlot()` ever changes,
 * the live index must change with it (a new migration).
 */

/**
 * @return list<string> the status values in an index's excluded-statuses list
 */
function lsIndexStatuses(string $index): array
{
    $definition = DB::selectOne('select pg_get_indexdef(indexrelid) as def from pg_index join pg_class on pg_class.oid = indexrelid where pg_class.relname = ?', [$index])->def;
    // Postgres prints `NOT IN ('a', 'b')` as `<> ALL ((ARRAY['a'::character varying, ...])::text[])`.
    preg_match('/ARRAY\[(.*?)\]/s', $definition, $list);
    preg_match_all("/'([a-z_]+)'/", $list[1] ?? '', $values);

    return $values[1];
}

it('has every DATA_MODEL column on lessons', function () {
    foreach ([
        'id', 'type', 'recurring_slot_id', 'learner_id', 'tutor_profile_id', 'booked_by_user_id', 'curriculum_id', 'subject_id',
        'starts_at', 'ends_at', 'duration_minutes', 'price', 'commission_pct', 'commission_amount', 'tutor_amount',
        'cancel_window_hours', 'student_grace_min', 'tutor_grace_min', 'status', 'payment_method_id', 'charge_attempts',
        'next_charge_at', 'room_provider', 'room_id', 'tutor_join_url', 'learner_join_url', 'room_created_at',
        'tutor_joined_at', 'learner_joined_at', 'room_closed_at', 'completed_at', 'cancelled_at', 'cancelled_by_user_id',
        'cancel_reason', 'report_due_at', 'escrow_released_at', 'created_at', 'updated_at',
    ] as $column) {
        expect(Schema::hasColumn('lessons', $column))->toBeTrue("lessons.{$column}");
    }

    expect(Schema::hasColumns('tutor_strikes', ['id', 'tutor_profile_id', 'lesson_id', 'type', 'note', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('tutor_strikes', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumns('ledger_entries', ['id', 'lesson_id', 'payout_id', 'dispute_id', 'account', 'tutor_profile_id', 'type', 'amount', 'memo', 'created_by_user_id', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('ledger_entries', 'updated_at'))->toBeFalse();
});

it('stores the price and the policy frozen on the row, as integer fils', function () {
    $lesson = Lesson::factory()->create(['price' => 12345, 'commission_pct' => 30, 'commission_amount' => 3704, 'tutor_amount' => 8641, 'cancel_window_hours' => 48, 'student_grace_min' => 20, 'tutor_grace_min' => 5])->fresh();

    expect($lesson->price->toFils())->toBe(12345)
        ->and($lesson->commission_pct)->toBe(30)
        ->and($lesson->commission_amount->toFils())->toBe(3704)
        ->and($lesson->tutor_amount->toFils())->toBe(8641)
        ->and([$lesson->cancel_window_hours, $lesson->student_grace_min, $lesson->tutor_grace_min])->toBe([48, 20, 5]);

    foreach (['price', 'commission_pct', 'commission_amount', 'tutor_amount', 'cancel_window_hours', 'student_grace_min', 'tutor_grace_min'] as $column) {
        expect(fn () => DB::transaction(fn () => Lesson::factory()->create([$column => null])))->toThrow(QueryException::class);
    }
});

it('bakes the CP2 slot index predicate from the enum and keeps it in step with it', function () {
    expect(lsIndexStatuses('lessons_tutor_slot_unique'))->toEqualCanonicalizing(LessonStatus::freeingSlotValues());
});

it('gives the one-trial-per-pair index the same freeing set, read back from the database', function () {
    expect(lsIndexStatuses('lessons_one_trial_per_pair'))->toEqualCanonicalizing(LessonStatus::freeingSlotValues());
});

it('allows one live trial per learner–tutor pair (invariant #12)', function () {
    $learner = Learner::factory()->create();
    $tutor = TutorProfile::factory()->create();
    $trial = fn (array $more = []) => Lesson::factory()->trial()->create(['learner_id' => $learner->id, 'tutor_profile_id' => $tutor->id, ...$more]);

    $first = $trial(['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);

    // A second live trial for the same pair is refused by the database, whatever the time.
    expect(fn () => DB::transaction(fn () => $trial(['starts_at' => now()->addDays(4), 'ends_at' => now()->addDays(4)->addHour()])))
        ->toThrow(QueryException::class, 'lessons_one_trial_per_pair');

    // A regular lesson of the same pair, another learner's trial, and another tutor's trial are fine.
    Lesson::factory()->create(['learner_id' => $learner->id, 'tutor_profile_id' => $tutor->id, 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHour()]);
    Lesson::factory()->trial()->create(['tutor_profile_id' => $tutor->id, 'starts_at' => now()->addDays(6), 'ends_at' => now()->addDays(6)->addHour()]);
    Lesson::factory()->trial()->create(['learner_id' => $learner->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);

    expect($first->fresh()->type)->toBe(LessonType::Trial);
});

it('does not let a cancelled, expired or refunded trial consume the trial', function (LessonStatus $freeing) {
    $learner = Learner::factory()->create();
    $tutor = TutorProfile::factory()->create();

    Lesson::factory()->trial()->withStatus($freeing)->create(['learner_id' => $learner->id, 'tutor_profile_id' => $tutor->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);

    // The pair can book a new trial.
    $again = Lesson::factory()->trial()->create(['learner_id' => $learner->id, 'tutor_profile_id' => $tutor->id, 'starts_at' => now()->addDays(4), 'ends_at' => now()->addDays(4)->addHour()]);
    expect($again->exists)->toBeTrue();
})->with(fn () => array_combine(LessonStatus::freeingSlotValues(), array_map(fn (LessonStatus $s) => [$s], LessonStatus::freeingSlot())));

it('does let a trial that was actually given consume it, whatever state it ended in', function (LessonStatus $live) {
    $learner = Learner::factory()->create();
    $tutor = TutorProfile::factory()->create();
    Lesson::factory()->trial()->withStatus($live)->create(['learner_id' => $learner->id, 'tutor_profile_id' => $tutor->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);

    expect(fn () => DB::transaction(fn () => Lesson::factory()->trial()->create(['learner_id' => $learner->id, 'tutor_profile_id' => $tutor->id, 'starts_at' => now()->addDays(9), 'ends_at' => now()->addDays(9)->addHour()])))
        ->toThrow(QueryException::class, 'lessons_one_trial_per_pair');
})->with(fn () => array_combine(
    array_map(fn (LessonStatus $s) => $s->value, array_values(array_filter(LessonStatus::cases(), fn (LessonStatus $s) => $s->blocksSlot()))),
    array_map(fn (LessonStatus $s) => [$s], array_values(array_filter(LessonStatus::cases(), fn (LessonStatus $s) => $s->blocksSlot()))),
));

it('still refuses two live lessons for one tutor at one time (the CP2 overlap index, untouched)', function () {
    $tutor = TutorProfile::factory()->create();
    $at = now()->addDays(3)->startOfHour();
    Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'starts_at' => $at, 'ends_at' => $at->copy()->addHour()]);

    expect(fn () => DB::transaction(fn () => Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'starts_at' => $at, 'ends_at' => $at->copy()->addHour()])))
        ->toThrow(QueryException::class, 'lessons_tutor_slot_unique');

    // A cancelled lesson frees it.
    Lesson::query()->where('tutor_profile_id', $tutor->id)->update(['status' => LessonStatus::CancelledByParent->value]);
    expect(Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'starts_at' => $at, 'ends_at' => $at->copy()->addHour()])->exists)->toBeTrue();
});

it('refuses two live lessons for one tutor whose intervals overlap without sharing a starts_at (3c)', function () {
    $tutor = TutorProfile::factory()->create();
    $at = now()->addDays(3)->startOfHour();
    Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'starts_at' => $at, 'ends_at' => $at->copy()->addHour()]);

    // Starts 30 minutes into the first lesson: shares no starts_at with it, so
    // lessons_tutor_slot_unique would let this through — only the GiST exclusion catches it.
    $overlapping = $at->copy()->addMinutes(30);
    expect(fn () => DB::transaction(fn () => Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'starts_at' => $overlapping, 'ends_at' => $overlapping->copy()->addHour()])))
        ->toThrow(QueryException::class, 'lessons_tutor_no_overlap');

    // A cancelled lesson frees the interval too.
    Lesson::query()->where('tutor_profile_id', $tutor->id)->update(['status' => LessonStatus::CancelledByParent->value]);
    expect(Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'starts_at' => $overlapping, 'ends_at' => $overlapping->copy()->addHour()])->exists)->toBeTrue();
});

it('allows back-to-back lessons for one tutor since bounds are half-open [)', function () {
    $tutor = TutorProfile::factory()->create();
    $at = now()->addDays(3)->startOfHour();
    Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'starts_at' => $at, 'ends_at' => $at->copy()->addHour()]);

    $backToBack = Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'starts_at' => $at->copy()->addHour(), 'ends_at' => $at->copy()->addHours(2)]);

    expect($backToBack->exists)->toBeTrue();
});

it('indexes the charging job only on reserved lessons', function () {
    $definition = DB::selectOne("select indexdef from pg_indexes where indexname = 'lessons_next_charge_at_reserved'")->indexdef;

    expect($definition)->toContain('next_charge_at')->toContain("status)::text = 'reserved'");
});
