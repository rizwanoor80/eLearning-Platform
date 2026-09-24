<?php

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\LessonStatus;
use App\Exceptions\LedgerException;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\TutorStrike;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * R52 / invariant #1: money moves only through LedgerService, entries are
 * append-only, and every lesson's entries sum to zero after every operation.
 */

/**
 * A lesson with a frozen split that adds up to its price. WHO gets the remainder fil
 * (R53 says the platform) is BookLesson's decision in 3c; the ledger only needs the
 * split to add up, and refuses a lesson whose split does not.
 */
function lgLesson(int $priceFils = 10000, int $pct = 25): Lesson
{
    $commission = Money::fils($priceFils)->percentage($pct);

    return Lesson::factory()->create([
        'price' => $priceFils,
        'commission_pct' => $pct,
        'commission_amount' => $commission->toFils(),
        'tutor_amount' => Money::fils($priceFils)->subtract($commission)->toFils(),
    ]);
}

function lgLedger(): LedgerService
{
    return app(LedgerService::class);
}

/**
 * @return array<int, array{string, string, int}> account, type, amount of the lesson's entries in order
 */
function lgLegs(Lesson $lesson): array
{
    return $lesson->ledgerEntries()->orderBy('id')->get()
        ->map(fn (LedgerEntry $e) => [$e->account->value, $e->type->value, $e->amount])->all();
}

// ---- the three operations -------------------------------------------------------------------

it('holds: gateway −price, escrow +price, and the lesson sums to zero', function () {
    $lesson = lgLesson();

    lgLedger()->hold($lesson);

    expect(lgLegs($lesson))->toBe([['gateway', 'hold', -10000], ['escrow', 'hold', 10000]])
        ->and(lgLedger()->sum($lesson))->toBe(0)
        ->and(lgLedger()->balance($lesson, LedgerAccount::Escrow))->toBe(10000);
});

it('releases: escrow to the tutor and to the platform in two zero-sum pairs, escrow back to zero', function () {
    $lesson = lgLesson();
    lgLedger()->hold($lesson);

    lgLedger()->release($lesson);

    expect(array_slice(lgLegs($lesson), 2))->toBe([
        ['escrow', 'release_tutor', -7500], ['tutor', 'release_tutor', 7500],
        ['escrow', 'release_commission', -2500], ['platform', 'release_commission', 2500],
    ])
        ->and(lgLedger()->sum($lesson))->toBe(0)
        ->and(lgLedger()->balance($lesson, LedgerAccount::Escrow))->toBe(0)
        ->and(lgLedger()->balance($lesson, LedgerAccount::Tutor))->toBe(7500)
        ->and(lgLedger()->balance($lesson, LedgerAccount::Platform))->toBe(2500);

    // The tutor leg carries the tutor for the balance buckets; the others do not.
    expect(LedgerEntry::query()->where('account', LedgerAccount::Tutor)->value('tutor_profile_id'))->toBe($lesson->tutor_profile_id)
        ->and(LedgerEntry::query()->where('account', '!=', LedgerAccount::Tutor)->whereNotNull('tutor_profile_id')->count())->toBe(0);
});

it('refunds in full: escrow −price, refund +price, escrow back to zero', function () {
    $lesson = lgLesson();
    lgLedger()->hold($lesson);

    lgLedger()->refund($lesson);

    expect(array_slice(lgLegs($lesson), 2))->toBe([['escrow', 'refund', -10000], ['refund', 'refund', 10000]])
        ->and(lgLedger()->sum($lesson))->toBe(0)
        ->and(lgLedger()->balance($lesson, LedgerAccount::Escrow))->toBe(0)
        ->and(lgLedger()->balance($lesson, LedgerAccount::Refund))->toBe(10000);
});

it('sums to zero after every step at prices that do not divide evenly, whoever the remainder fil went to', function (int $price, int $pct) {
    $lesson = lgLesson($price, $pct);
    // Frozen split adds up exactly (R53), whatever the rounding did.
    expect($lesson->commission_amount->toFils() + $lesson->tutor_amount->toFils())->toBe($price);

    lgLedger()->hold($lesson);
    expect(lgLedger()->sum($lesson))->toBe(0);
    lgLedger()->release($lesson);
    expect(lgLedger()->sum($lesson))->toBe(0)
        ->and(lgLedger()->balance($lesson, LedgerAccount::Tutor) + lgLedger()->balance($lesson, LedgerAccount::Platform))->toBe($price);
})->with([
    'odd fils, 25%' => [10001, 25],
    'one fil, 25%' => [1, 25],
    'prime price, 33%' => [12347, 33],
    'half-fil commission rounds up' => [10002, 25],
    'zero commission' => [10000, 0],
]);

// ---- preconditions -------------------------------------------------------------------------

it('refuses a second hold, a release or refund with nothing held, and a second release or refund', function () {
    $lesson = lgLesson();

    expect(fn () => lgLedger()->release($lesson))->toThrow(LedgerException::class, 'escrow')
        ->and(fn () => lgLedger()->refund($lesson))->toThrow(LedgerException::class, 'escrow');

    lgLedger()->hold($lesson);
    expect(fn () => lgLedger()->hold($lesson))->toThrow(LedgerException::class, 'already has a hold');

    lgLedger()->release($lesson);
    expect(fn () => lgLedger()->release($lesson))->toThrow(LedgerException::class)
        ->and(fn () => lgLedger()->refund($lesson))->toThrow(LedgerException::class)
        ->and(fn () => lgLedger()->hold($lesson))->toThrow(LedgerException::class)
        ->and(lgLedger()->sum($lesson))->toBe(0)
        ->and($lesson->ledgerEntries()->count())->toBe(6);
});

it('refuses a release after a refund, and a refund after a release', function () {
    $refunded = lgLesson();
    lgLedger()->hold($refunded);
    lgLedger()->refund($refunded);
    expect(fn () => lgLedger()->release($refunded))->toThrow(LedgerException::class)->and($refunded->ledgerEntries()->count())->toBe(4);

    $released = lgLesson();
    lgLedger()->hold($released);
    lgLedger()->release($released);
    expect(fn () => lgLedger()->refund($released))->toThrow(LedgerException::class)->and($released->ledgerEntries()->count())->toBe(6);
});

it('refuses a lesson whose frozen split does not add up to its price, writing nothing', function () {
    $lesson = lgLesson();
    Lesson::query()->whereKey($lesson->id)->update(['tutor_amount' => 7499]);

    expect(fn () => lgLedger()->hold($lesson))->toThrow(LedgerException::class, 'does not equal the price')
        ->and(LedgerEntry::query()->count())->toBe(0);

    $free = lgLesson();
    Lesson::query()->whereKey($free->id)->update(['price' => 0, 'tutor_amount' => 0, 'commission_amount' => 0]);
    expect(fn () => lgLedger()->hold($free))->toThrow(LedgerException::class, 'no price');
});

it('rolls the whole operation back when the lesson would not sum to zero', function () {
    $lesson = lgLesson();
    lgLedger()->hold($lesson);
    // A stray entry, planted by raw SQL (the trigger only blocks UPDATE and DELETE), unbalances the lesson.
    DB::table('ledger_entries')->insert(['lesson_id' => $lesson->id, 'account' => 'platform', 'type' => 'goodwill', 'amount' => 5, 'created_at' => now()]);

    // The escrow still equals the price, so the release is allowed to write — and its zero-sum check then trips.
    expect(fn () => lgLedger()->release($lesson))->toThrow(LedgerException::class, 'sum to zero')
        ->and($lesson->ledgerEntries()->count())->toBe(3);
});

it('locks the lesson row before it reads anything, inside a transaction', function () {
    $lesson = lgLesson();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
    });
    $baseline = DB::transactionLevel();

    lgLedger()->hold($lesson);

    $lock = collect($queries)->search(fn ($q) => str_contains($q['sql'], 'from "lessons"') && str_contains($q['sql'], 'for update'));
    $firstLedgerRead = collect($queries)->search(fn ($q) => str_contains($q['sql'], 'ledger_entries') && str_contains($q['sql'], 'select'));

    expect($lock)->not->toBeFalse()->and($firstLedgerRead)->not->toBeFalse()
        ->and($lock)->toBeLessThan($firstLedgerRead)
        ->and($queries[$lock]['level'])->toBeGreaterThan($baseline);
});

it('records who did it', function () {
    $admin = User::factory()->admin()->create();
    $lesson = lgLesson();

    lgLedger()->hold($lesson, $admin);

    expect(LedgerEntry::query()->pluck('created_by_user_id')->unique()->all())->toBe([$admin->id]);
});

it('declares settle and payout for CP5/CP8 and refuses to run them', function () {
    expect(fn () => lgLedger()->settle())->toThrow(LogicException::class, 'CP8')
        ->and(fn () => lgLedger()->payout())->toThrow(LogicException::class, 'CP5');
});

// ---- append-only (invariant #1) ----------------------------------------------------------------

it('refuses to update or delete an entry through the model', function () {
    $lesson = lgLesson();
    lgLedger()->hold($lesson);
    $entry = LedgerEntry::query()->firstOrFail();

    $entry->amount = 1;
    expect(fn () => $entry->save())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $entry->delete())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $entry->update(['memo' => 'x']))->toThrow(LogicException::class)
        ->and(LedgerEntry::query()->count())->toBe(2);
});

it('refuses to update or delete an entry in the database itself, even with raw SQL', function () {
    $lesson = lgLesson();
    lgLedger()->hold($lesson);

    // Each in its own savepoint: a failed statement aborts a Postgres transaction.
    expect(fn () => DB::transaction(fn () => DB::table('ledger_entries')->update(['amount' => 1])))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('ledger_entries')->delete()))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => LedgerEntry::query()->update(['memo' => 'x'])))->toThrow(QueryException::class, 'append-only');

    expect(lgLegs($lesson))->toBe([['gateway', 'hold', -10000], ['escrow', 'hold', 10000]]);
});

it('refuses to create an entry outside LedgerService', function () {
    $lesson = lgLesson();

    expect(fn () => LedgerEntry::query()->create(['lesson_id' => $lesson->id, 'account' => LedgerAccount::Escrow, 'type' => LedgerEntryType::Hold, 'amount' => 1]))
        ->toThrow(LogicException::class, 'only by LedgerService')
        ->and(fn () => (new LedgerEntry(['lesson_id' => $lesson->id, 'account' => 'escrow', 'type' => 'hold', 'amount' => 1]))->save())
        ->toThrow(LogicException::class)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

// ---- ledger:verify ---------------------------------------------------------------------------------

it('passes ledger:verify on a sound ledger and on an empty one', function () {
    $this->artisan('ledger:verify')->expectsOutputToContain('Ledger OK')->assertExitCode(0);

    $a = lgLesson();
    lgLedger()->hold($a);
    lgLedger()->release($a);
    $b = lgLesson(10001);
    lgLedger()->hold($b);
    lgLedger()->refund($b);

    $this->artisan('ledger:verify')->assertExitCode(0);
    expect(lgLedger()->unbalancedLessons())->toBeEmpty();
});

it('fails ledger:verify and names the lesson when one does not sum to zero', function () {
    $sound = lgLesson();
    lgLedger()->hold($sound);
    $broken = lgLesson();
    lgLedger()->hold($broken);
    DB::table('ledger_entries')->insert(['lesson_id' => $broken->id, 'account' => 'platform', 'type' => 'goodwill', 'amount' => 7, 'created_at' => now()]);

    $this->artisan('ledger:verify')->expectsOutputToContain("Lesson {$broken->id} sums to 7 fils, not zero.")->assertExitCode(1);

    expect(lgLedger()->unbalancedLessons()->pluck('lesson_id')->all())->toBe([$broken->id]);
});

it('runs ledger:verify from the composer test script, after Pest', function () {
    $scripts = json_decode(file_get_contents(base_path('composer.json')), true)['scripts']['test'];

    expect(array_search('@php artisan ledger:verify --no-interaction', $scripts, true))->toBeGreaterThan(array_search('@php artisan test', $scripts, true));
});

// ---- money rules (R53) ---------------------------------------------------------------------------

it('keeps floats and rounding out of the money code', function () {
    $paths = [
        'app/Services/Ledger/LedgerService.php',
        'app/Services/Lessons/LessonStateMachine.php',
        'app/Models/LedgerEntry.php',
        'app/Models/Lesson.php',
        // R77 item 5: widened to every 3c money file the round-1 review named.
        'app/Actions/Lessons/BookLesson.php',
        'app/Support/Money.php',
        'app/Models/Payment.php',
        'app/Services/Payments/FakePaymentGateway.php',
        'app/Services/Payments/PaymentCaptureResult.php',
        'app/Services/Payments/PaymentGateway.php',
        'app/Console/Commands/ExpireUnpaidLessons.php',
        'app/Models/TutorProfile.php',
        'resources/js/pages/tutor/Onboarding.vue',
    ];

    foreach ($paths as $path) {
        $source = file_get_contents(base_path($path));

        expect($source)->not->toMatch('/\bround\s*\(|\bfloor\s*\(|\bceil\s*\(|\(float\)|\bfloatval\s*\(|\bdoubleval\s*\(/');
    }
});

it('links strikes to a tutor and, optionally, a lesson', function () {
    $lesson = lgLesson();
    $strike = TutorStrike::factory()->create(['tutor_profile_id' => $lesson->tutor_profile_id, 'lesson_id' => $lesson->id]);

    expect($strike->fresh()->lesson->is($lesson))->toBeTrue()->and($strike->type->value)->toBe('late_cancel')
        ->and(TutorStrike::factory()->create()->lesson_id)->toBeNull();
    expect(LessonStatus::Confirmed)->toBe($lesson->status);
});
