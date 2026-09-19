<?php

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Events\Lessons\LessonStatusChanged;
use App\Exceptions\LessonTransitionException;
use App\Models\Lesson;
use App\Services\Lessons\LessonStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * R51: every state and every edge of DATA_MODEL's diagram is declared and guarded,
 * with a test per allowed and per forbidden edge; only LessonStateMachine writes
 * `lessons.status` (invariant #2).
 */

/**
 * @return Generator<string, array{LessonStatus, LessonStatus}>
 */
function smPairs(): Generator
{
    foreach (LessonStatus::cases() as $from) {
        foreach (LessonStatus::cases() as $to) {
            yield "{$from->value} → {$to->value}" => [$from, $to];
        }
    }
}

it('declares all seventeen states and exactly the edges of the diagram', function () {
    expect(LessonStatus::cases())->toHaveCount(17)
        ->and(array_keys(LessonStateMachine::edges()))->toHaveCount(17);

    $allowed = [];
    foreach (LessonStateMachine::edges() as $from => $targets) {
        foreach ($targets as $to) {
            $allowed[] = "{$from} → {$to->value}";
        }
    }

    // DATA_MODEL's diagram, edge by edge, plus its three documented auto-advances.
    expect($allowed)->toEqualCanonicalizing([
        'pending_payment → confirmed', 'pending_payment → expired',
        'reserved → confirmed', 'reserved → cancelled_payment_failed', 'reserved → cancelled_by_parent', 'reserved → cancelled_by_tutor',
        'confirmed → cancelled_by_parent', 'confirmed → cancelled_by_tutor', 'confirmed → in_progress', 'confirmed → no_show_both', 'confirmed → provider_failure',
        'in_progress → completed', 'in_progress → no_show_student', 'in_progress → no_show_tutor',
        'completed → completed_reported', 'completed → disputed',
        'completed_reported → disputed',
        'disputed → settled',
        'cancelled_by_parent → refunded', 'cancelled_by_parent → completed_reported',
        'no_show_student → completed_reported', 'no_show_tutor → refunded', 'no_show_both → refunded',
    ]);
});

it('allows an edge exactly when it is in the table (all 289 pairs)', function (LessonStatus $from, LessonStatus $to) {
    $inTable = in_array($to, LessonStateMachine::edges()[$from->value], true);

    expect(LessonStateMachine::allows($from, $to))->toBe($inTable);

    if ($inTable) {
        LessonStateMachine::assert($from, $to);
    } else {
        expect(fn () => LessonStateMachine::assert($from, $to))->toThrow(LessonTransitionException::class);
    }
})->with(fn () => smPairs());

it('moves a stored lesson along every allowed edge, firing the event with the edge', function (LessonStatus $from, LessonStatus $to) {
    Event::fake([LessonStatusChanged::class]);
    $lesson = Lesson::factory()->withStatus($from)->create();

    $moved = LessonStateMachine::transition($lesson, $to);

    expect($moved->status)->toBe($to)->and($lesson->fresh()->status)->toBe($to);
    Event::assertDispatched(LessonStatusChanged::class, fn (LessonStatusChanged $e) => $e->from === $from && $e->to === $to && $e->lesson->is($lesson));
})->with(function () {
    foreach (smPairs() as $label => [$from, $to]) {
        if (LessonStateMachine::allows($from, $to)) {
            yield $label => [$from, $to];
        }
    }
});

it('refuses every edge that is not in the table from a stored lesson, changing nothing and firing nothing', function (LessonStatus $from) {
    Event::fake([LessonStatusChanged::class]);
    $lesson = Lesson::factory()->withStatus($from)->create();

    foreach (LessonStatus::cases() as $to) {
        if (LessonStateMachine::allows($from, $to)) {
            continue;
        }

        expect(fn () => LessonStateMachine::transition($lesson, $to))->toThrow(LessonTransitionException::class, "{$from->value} to {$to->value}");
        expect($lesson->fresh()->status)->toBe($from);
    }

    Event::assertNotDispatched(LessonStatusChanged::class);
})->with(fn () => array_combine(array_map(fn (LessonStatus $s) => $s->value, LessonStatus::cases()), array_map(fn (LessonStatus $s) => [$s], LessonStatus::cases())));

it('creates a lesson only in one of the two initial states, and fires the event with no from', function () {
    Event::fake([LessonStatusChanged::class]);
    $attributes = Lesson::factory()->make()->getAttributes();
    unset($attributes['status']);
    $attributes = array_map(fn ($v) => $v instanceof BackedEnum ? $v->value : $v, $attributes);

    foreach ([LessonStatus::PendingPayment, LessonStatus::Reserved] as $index => $initial) {
        $attributes['starts_at'] = now()->addDays(10 + $index);
        $attributes['ends_at'] = now()->addDays(10 + $index)->addHour();
        $lesson = LessonStateMachine::open($attributes + [], $initial);

        expect($lesson->exists)->toBeTrue()->and($lesson->fresh()->status)->toBe($initial);
        Event::assertDispatched(LessonStatusChanged::class, fn (LessonStatusChanged $e) => $e->from === null && $e->to === $initial);
    }

    foreach (array_filter(LessonStatus::cases(), fn (LessonStatus $other) => ! in_array($other, LessonStateMachine::initialStates(), true)) as $other) {
        expect(fn () => LessonStateMachine::open($attributes, $other))->toThrow(LessonTransitionException::class);
    }
});

it('decides on the locked status, not on a stale copy', function () {
    $lesson = Lesson::factory()->withStatus(LessonStatus::Confirmed)->create();
    $stale = Lesson::query()->findOrFail($lesson->id);

    // Another request cancelled it meanwhile.
    LessonStateMachine::transition($lesson, LessonStatus::CancelledByParent);

    expect($stale->status)->toBe(LessonStatus::Confirmed)
        ->and(fn () => LessonStateMachine::transition($stale, LessonStatus::InProgress))->toThrow(LessonTransitionException::class);

    // ... and a stale copy of an allowed edge still works once it reads the locked row.
    $second = Lesson::query()->findOrFail($lesson->id);
    LessonStateMachine::transition($second, LessonStatus::Refunded);
    expect($lesson->fresh()->status)->toBe(LessonStatus::Refunded);
});

it('runs the caller’s work in the same transaction, so a failure there writes nothing', function () {
    Event::fake([LessonStatusChanged::class]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::Confirmed)->create();

    expect(fn () => LessonStateMachine::transition($lesson, LessonStatus::CancelledByParent, function (Lesson $l): void {
        $l->forceFill(['cancelled_at' => now(), 'cancel_reason' => 'x']);
        $l->save();

        throw new RuntimeException('ledger failed');
    }))->toThrow(RuntimeException::class, 'ledger failed');

    $fresh = $lesson->fresh();
    expect($fresh->status)->toBe(LessonStatus::Confirmed)->and($fresh->cancelled_at)->toBeNull()->and($fresh->cancel_reason)->toBeNull();
    Event::assertNotDispatched(LessonStatusChanged::class);

    // ... and on success the work's changes are saved together with the status.
    LessonStateMachine::transition($lesson, LessonStatus::CancelledByParent, fn (Lesson $l) => $l->forceFill(['cancelled_at' => now(), 'cancel_reason' => 'Ill']));
    expect($lesson->fresh()->cancel_reason)->toBe('Ill')->and($lesson->fresh()->cancelled_at)->not->toBeNull();
});

it('fires the event only after the outermost transaction commits', function () {
    $seen = [];
    Event::listen(LessonStatusChanged::class, function () use (&$seen) {
        $seen[] = DB::transactionLevel();
    });
    $lesson = Lesson::factory()->withStatus(LessonStatus::Confirmed)->create();
    $baseline = DB::transactionLevel();

    DB::transaction(function () use ($lesson, &$seen) {
        LessonStateMachine::transition($lesson, LessonStatus::CancelledByParent);
        expect($seen)->toBe([]); // still inside the caller's transaction: not yet
    });

    expect($seen)->toBe([$baseline]);
});

it('does not fire the event when the surrounding transaction rolls back', function () {
    Event::fake([LessonStatusChanged::class]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::Confirmed)->create();

    try {
        DB::transaction(function () use ($lesson) {
            LessonStateMachine::transition($lesson, LessonStatus::CancelledByParent);

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(LessonStatusChanged::class);
    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
});

// ---- nothing else writes lessons.status (invariant #2) ----------------------------------------

it('refuses any write to lessons.status outside the state machine', function () {
    $lesson = Lesson::factory()->withStatus(LessonStatus::Confirmed)->create();

    $lesson->status = LessonStatus::Expired;
    expect(fn () => $lesson->save())->toThrow(LogicException::class, 'LessonStateMachine');

    // Mass assignment cannot reach it at all (not fillable); forcing it is refused.
    $lesson = $lesson->fresh();
    $lesson->update(['status' => LessonStatus::Expired]);
    expect($lesson->fresh()->status)->toBe(LessonStatus::Confirmed)
        ->and(fn () => $lesson->forceFill(['status' => LessonStatus::Expired])->save())->toThrow(LogicException::class)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);

    // Other columns are untouched by the guard.
    $lesson = $lesson->fresh();
    $lesson->forceFill(['cancel_reason' => 'Ill'])->save();
    expect($lesson->fresh()->cancel_reason)->toBe('Ill');
});

it('has no write to lessons.status under app/ except the state machine', function () {
    $offenders = [];
    $allowed = [str_replace('/', DIRECTORY_SEPARATOR, 'app/Services/Lessons/LessonStateMachine.php')];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        $source = file_get_contents($file->getPathname());

        // Only files that deal with lessons: a write to a tutor's or a document's status is not ours.
        if (! preg_match('/\bLesson\b/', $source) || in_array($relative, $allowed, true) || str_ends_with($relative, 'Models'.DIRECTORY_SEPARATOR.'Lesson.php')) {
            continue;
        }

        if (preg_match('/->status\s*=[^=]|[\'"]status[\'"]\s*=>|allowingStatusWrites/', $source)) {
            $offenders[] = $relative;
        }
    }

    // Files that mention Lesson AND assign some other model's status are reviewed by name.
    $reviewed = array_map(fn ($p) => str_replace('/', DIRECTORY_SEPARATOR, $p), []);
    expect(array_values(array_diff($offenders, $reviewed)))->toBe([]);
});

it('gives the factory the one other key to the status guard, and nothing under app/ uses it', function () {
    $callers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_contains(file_get_contents($file->getPathname()), 'allowingStatusWrites(')) {
            $callers[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    sort($callers);
    expect($callers)->toBe([
        'app'.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'Lesson.php',
        'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Lessons'.DIRECTORY_SEPARATOR.'LessonStateMachine.php',
    ]);
});

it('keeps type and the frozen price on the row it opens', function () {
    $attributes = Lesson::factory()->trial()->make()->getAttributes();
    unset($attributes['status']);
    $attributes = array_map(fn ($v) => $v instanceof BackedEnum ? $v->value : $v, $attributes);

    $lesson = LessonStateMachine::open($attributes, LessonStatus::PendingPayment)->fresh();

    expect($lesson->type)->toBe(LessonType::Trial)
        ->and($lesson->price->toFils())->toBe(10000)
        ->and($lesson->commission_amount->toFils() + $lesson->tutor_amount->toFils())->toBe(10000)
        ->and($lesson->cancel_window_hours)->toBe(24);
});
