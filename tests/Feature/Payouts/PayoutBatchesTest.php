<?php

use App\Actions\Lessons\SettleSuspendedLesson;
use App\Actions\Payouts\ExportPayoutBatchCsv;
use App\Actions\Payouts\GeneratePayoutBatch;
use App\Actions\Payouts\MarkPayoutLineFailed;
use App\Actions\Payouts\MarkPayoutLinePaid;
use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\LessonStatus;
use App\Enums\PayoutLineStatus;
use App\Enums\PayoutMethodCode;
use App\Enums\TutorProfileStatus;
use App\Exceptions\LedgerException;
use App\Exceptions\PayoutException;
use App\Filament\Resources\PayoutBatches\Pages\ListPayoutBatches;
use App\Filament\Resources\PayoutBatches\Pages\ViewPayoutBatch;
use App\Filament\Resources\PayoutBatches\PayoutBatchResource;
use App\Filament\Resources\PayoutBatches\RelationManagers\LinesRelationManager;
use App\Filament\Resources\TutorProfiles\Schemas\TutorProfileInfolist;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\PayoutBatch;
use App\Models\PayoutBatchLine;
use App\Models\PayoutMethod;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Tutors\TutorEarnings;
use App\Support\Facades\Settings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

/**
 * 23h (R251, R253): payout batches. Generation, the CSV per method, mark paid / failed, the `payout` ledger entries
 * (`LedgerService::payout()`), what `ledger:verify` makes of them, and the buckets and earnings page after a payout.
 */
function pbTutor(int $lessons = 3, array $profile = [], ?LessonStatus $status = null): TutorProfile
{
    static $slot = 0;

    $tutor = TutorProfile::factory()->approved()->create($profile + [
        'country' => 'AE',
        'payout_method' => 'bank_transfer',
        'user_id' => User::factory()->tutor(),
    ]);

    // AED 75.00 tutor share each: three lessons are AED 225.00, over the AED 200.00 minimum. They ended days ago, so their
    // dispute window (R258) has closed and the money is available.
    for ($i = 0; $i < $lessons; $i++) {
        $lesson = Lesson::factory()->withStatus(LessonStatus::Completed)->startingAt(now()->subDays(60)->startOfHour()->addHours($slot++))->create(['tutor_profile_id' => $tutor->id]);
        app(LedgerService::class)->hold($lesson);
        app(LedgerService::class)->release($lesson);
        Lesson::allowingStatusWrites(fn () => $lesson->forceFill(['status' => $status ?? LessonStatus::CompletedReported])->save());
    }

    return $tutor;
}

function pbAvailable(TutorProfile $tutor): int
{
    return app(TutorEarnings::class)->buckets($tutor)->available->toFils();
}

function pbGenerate(?User $admin = null, ?CarbonImmutable $end = null)
{
    return app(GeneratePayoutBatch::class)($admin ?? User::factory()->admin()->create(), $end);
}

function pbLine(TutorProfile $tutor, ?User $admin = null): PayoutBatchLine
{
    $result = pbGenerate($admin);

    return $result->batch->lines()->where('tutor_profile_id', $tutor->id)->firstOrFail();
}

function pbPay(PayoutBatchLine $line, string $reference = 'BANK-REF-1'): void
{
    app(MarkPayoutLinePaid::class)(User::factory()->admin()->create(), $line, $reference);
}

// --- LedgerService::payout ---------------------------------------------------------------------------------------

it('writes a tutor leg and a bank leg that sum to zero, and nothing on any lesson', function () {
    $tutor = pbTutor();
    $line = pbLine($tutor);
    $lessonSums = LedgerEntry::query()->whereNotNull('lesson_id')->sum('amount');

    app(LedgerService::class)->payout($line);

    $entries = LedgerEntry::query()->where('payout_id', $line->id)->orderBy('id')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->sum(fn (LedgerEntry $e) => $e->amount))->toBe(0)
        ->and($entries[0]->account)->toBe(LedgerAccount::Tutor)
        ->and($entries[0]->amount)->toBe(-22500)
        ->and($entries[0]->tutor_profile_id)->toBe($tutor->id)
        ->and($entries[1]->account)->toBe(LedgerAccount::Bank)
        ->and($entries[1]->amount)->toBe(22500)
        ->and($entries->every(fn (LedgerEntry $e) => $e->type === LedgerEntryType::Payout && $e->lesson_id === null))->toBeTrue()
        ->and((int) LedgerEntry::query()->whereNotNull('lesson_id')->sum('amount'))->toBe((int) $lessonSums);

    expect(app(LedgerService::class)->unbalancedLessons())->toBeEmpty()
        // The entries balance, but the line is still pending, which `ledger:verify` reports until MarkPayoutLinePaid settles it.
        ->and(app(LedgerService::class)->unbalancedPayouts()->pluck('payout_id')->all())->toBe([$line->id]);

    DB::table('payout_batch_lines')->where('id', $line->id)->update(['status' => 'paid', 'external_reference' => 'X', 'paid_at' => now()]);

    expect(app(LedgerService::class)->unbalancedPayouts())->toBeEmpty();
});

it('refuses to pay a line twice', function () {
    $line = pbLine(pbTutor());
    $ledger = app(LedgerService::class);
    $ledger->payout($line);
    PayoutBatchLine::query()->whereKey($line->id)->update(['status' => PayoutLineStatus::Pending->value]);

    expect(fn () => $ledger->payout($line->fresh()))->toThrow(LedgerException::class, 'already been paid');
    expect(LedgerEntry::query()->where('payout_id', $line->id)->count())->toBe(2);
});

it('has a database index that stops a second payout leg for the same account even if the service were bypassed', function () {
    $line = pbLine(pbTutor());
    app(LedgerService::class)->payout($line);

    expect(fn () => DB::table('ledger_entries')->insert(['payout_id' => $line->id, 'account' => 'tutor', 'type' => 'payout', 'amount' => -1, 'tutor_profile_id' => $line->tutor_profile_id, 'created_at' => now()]))
        ->toThrow(QueryException::class);
});

it('refuses a payout of more than the tutor has been released on the ledger', function () {
    $tutor = pbTutor();
    $line = pbLine($tutor);
    DB::table('payout_batch_lines')->where('id', $line->id)->update(['amount' => 22501]);

    expect(fn () => app(LedgerService::class)->payout($line->fresh()))->toThrow(LedgerException::class, 'more than the tutor');
    expect(LedgerEntry::query()->where('payout_id', $line->id)->exists())->toBeFalse();
});

it('refuses a line that is not pending', function () {
    $line = pbLine(pbTutor());
    app(MarkPayoutLineFailed::class)(User::factory()->admin()->create(), $line, 'Bounced');

    expect(fn () => app(LedgerService::class)->payout($line->fresh()))->toThrow(LedgerException::class, 'not pending');
});

it('shapes a payout entry in the database: a payout id and no lesson, and no other kind with a payout id', function () {
    $line = pbLine(pbTutor());
    $lesson = Lesson::factory()->create();

    expect(fn () => DB::table('ledger_entries')->insert(['payout_id' => null, 'account' => 'bank', 'type' => 'payout', 'amount' => 1, 'created_at' => now()]))->toThrow(QueryException::class);
    expect(fn () => DB::table('ledger_entries')->insert(['payout_id' => $line->id, 'lesson_id' => $lesson->id, 'account' => 'bank', 'type' => 'payout', 'amount' => 1, 'created_at' => now()]))->toThrow(QueryException::class);
    expect(fn () => DB::table('ledger_entries')->insert(['payout_id' => $line->id, 'account' => 'platform', 'type' => 'goodwill', 'amount' => 1, 'created_at' => now()]))->toThrow(QueryException::class);
});

it('has ledger:verify name an unbalanced payout, a paid line with no entries, an unpaid line with entries and a stray entry', function () {
    $paid = pbLine(pbTutor());
    pbPay($paid);
    expect(app(LedgerService::class)->unbalancedPayouts())->toBeEmpty()
        ->and(app(LedgerService::class)->strayEntries())->toBeEmpty();
    $this->artisan('ledger:verify')->expectsOutputToContain('Ledger OK')->assertExitCode(0);

    // Paid per the line, but one leg missing.
    DB::table('payout_batch_lines')->where('id', $paid->id)->update(['status' => 'paid']);
    $tutor2 = pbTutor();
    $second = pbLine($tutor2);
    DB::table('payout_batch_lines')->where('id', $second->id)->update(['status' => 'paid', 'external_reference' => 'X', 'paid_at' => now()]);
    // Pending line with entries.
    $tutor3 = pbTutor();
    $third = pbLine($tutor3);
    DB::table('ledger_entries')->insert(['payout_id' => $third->id, 'account' => 'bank', 'type' => 'payout', 'amount' => 5, 'created_at' => now()]);
    DB::table('ledger_entries')->insert(['account' => 'platform', 'type' => 'goodwill', 'amount' => 0, 'created_at' => now()]);

    $ids = app(LedgerService::class)->unbalancedPayouts()->pluck('payout_id')->all();

    expect($ids)->toContain($second->id)->toContain($third->id)->not->toContain($paid->id)
        ->and(app(LedgerService::class)->strayEntries())->toHaveCount(1);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain("Payout line {$second->id}")
        ->expectsOutputToContain("Payout line {$third->id}")
        ->expectsOutputToContain('belongs to neither a lesson nor a payout')
        ->assertExitCode(1);
});

// --- Generation -------------------------------------------------------------------------------------------------

it('makes one line per qualifying tutor for the whole available amount, with a reference and a snapshot of the details', function () {
    $tutor = pbTutor(3, ['bank_account_name' => 'Synthetic Tutor', 'bank_iban' => 'GB00SYNTH0000000000001', 'bank_swift' => 'SYNTGB2L']);
    $other = pbTutor(2); // AED 150.00, under the AED 200.00 minimum
    $admin = User::factory()->admin()->create();

    $result = pbGenerate($admin, CarbonImmutable::parse('2026-10-18'));

    expect($result->batch)->not->toBeNull()
        ->and($result->skipped)->toBe([])
        ->and($result->batch->lines)->toHaveCount(1)
        ->and($result->batch->created_by_user_id)->toBe($admin->id)
        ->and($result->batch->period_end->toDateString())->toBe('2026-10-18');

    $line = $result->batch->lines->first();

    expect($line->tutor_profile_id)->toBe($tutor->id)
        ->and($line->amount->toFils())->toBe(22500)
        ->and($line->currency)->toBe('AED')
        ->and($line->status)->toBe(PayoutLineStatus::Pending)
        ->and($line->reference)->toBe(sprintf('TT-%05d-001', $result->batch->id))
        ->and($line->details['iban'])->toBe('GB00SYNTH0000000000001')
        ->and($line->details['account_name'])->toBe('Synthetic Tutor');

    // The details are copied: a later change of the tutor's profile does not reach the line.
    $tutor->update(['bank_iban' => 'GB00CHANGED00000000002']);
    expect($line->fresh()->details['iban'])->toBe('GB00SYNTH0000000000001');

    // Processing now, nothing paid, no ledger movement.
    expect(app(TutorEarnings::class)->buckets($tutor)->processing->toFils())->toBe(22500)
        ->and(pbAvailable($tutor))->toBe(0)
        ->and(pbAvailable($other))->toBe(15000)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Payout->value)->exists())->toBeFalse();

    expect(AuditLog::query()->where('action', 'payout_batch.generated')->count())->toBe(1);
});

it('makes no batch when nobody qualifies', function () {
    pbTutor(1);

    $result = pbGenerate();

    expect($result->batch)->toBeNull()->and(PayoutBatch::query()->count())->toBe(0);
});

it('includes a tutor at exactly the minimum and leaves one a fil under', function () {
    $exact = pbTutor(0);
    $under = pbTutor(0);
    Settings::set('payout_min', 7500);

    foreach ([$exact, $under] as $t) {
        $lesson = Lesson::factory()->withStatus(LessonStatus::Completed)->startingAt(now()->subDays(60)->startOfHour()->addHours($t->id % 200))->create(['tutor_profile_id' => $t->id]);
        app(LedgerService::class)->hold($lesson);
        app(LedgerService::class)->release($lesson);
    }

    Settings::set('payout_min', 7501);
    expect(pbGenerate()->batch)->toBeNull();

    Settings::set('payout_min', 7500);
    $result = pbGenerate();
    expect($result->batch->lines)->toHaveCount(2);
});

it('makes one file-worth of lines for two methods and exports each on its own', function () {
    PayoutMethod::query()->where('code', 'wise')->update(['is_active' => true, 'countries' => json_encode([])]);
    $bank = pbTutor(3, ['bank_account_name' => 'Bank Tutor', 'bank_iban' => 'GB00BANK00000000000001', 'bank_swift' => 'BANKGB2L']);
    $wise = pbTutor(3, ['payout_method' => 'wise', 'payout_email' => 'wise.tutor@example.test']);

    $batch = pbGenerate()->batch;
    expect($batch->lines)->toHaveCount(2);

    $export = app(ExportPayoutBatchCsv::class);
    $bankCsv = $export->csv(PayoutMethodCode::BankTransfer, $export->lines($batch, PayoutMethodCode::BankTransfer));
    $wiseCsv = $export->csv(PayoutMethodCode::Wise, $export->lines($batch, PayoutMethodCode::Wise));
    $bankLine = $batch->lines->firstWhere('tutor_profile_id', $bank->id);
    $wiseLine = $batch->lines->firstWhere('tutor_profile_id', $wise->id);

    expect($bankCsv)->toBe("\"Account name\",IBAN,SWIFT,Amount,Currency,Reference\n\"Bank Tutor\",GB00BANK00000000000001,BANKGB2L,225.00,AED,{$bankLine->reference}\n")
        ->and($wiseCsv)->toBe("Email,Amount,Currency,Reference\nwise.tutor@example.test,225.00,AED,{$wiseLine->reference}\n")
        ->and($bankCsv)->not->toContain('wise.tutor')
        ->and($wiseCsv)->not->toContain('GB00BANK');
});

it('reports a tutor who has the minimum but cannot be paid, and a tutor with an unpaid line already', function () {
    $noMethod = pbTutor(3, ['payout_method' => null]);
    $closed = pbTutor(3);
    $closed->user->delete();
    $ready = pbTutor(3);

    $result = pbGenerate();

    expect($result->batch->lines->pluck('tutor_profile_id')->all())->toBe([$ready->id])
        ->and(collect($result->skipped)->pluck('tutor_profile_id')->sort()->values()->all())->toBe(collect([$noMethod->id, $closed->id])->sort()->values()->all());

    // Money paid later is not in the old line, but the tutor already has a line waiting.
    $more = Lesson::factory()->withStatus(LessonStatus::Completed)->startingAt(now()->subDays(61)->startOfHour())->create(['tutor_profile_id' => $ready->id]);
    foreach ([1, 2, 3] as $i) {
        $l = Lesson::factory()->withStatus(LessonStatus::Completed)->startingAt(now()->subDays(62)->startOfHour()->addHours($i))->create(['tutor_profile_id' => $ready->id]);
        app(LedgerService::class)->hold($l);
        app(LedgerService::class)->release($l);
    }
    $second = pbGenerate();

    expect(collect($second->skipped)->firstWhere('tutor_profile_id', $ready->id)['reason'])->toContain('Already has a payout waiting');
});

it('still pays a suspended tutor what they earned', function () {
    $tutor = pbTutor(3);
    $tutor->update(['status' => TutorProfileStatus::Suspended]);

    expect(pbGenerate()->batch->lines->pluck('tutor_profile_id')->all())->toBe([$tutor->id]);
});

it('does not pay money on a disputed lesson', function () {
    $tutor = pbTutor(3, status: LessonStatus::Disputed);

    expect(pbGenerate()->batch)->toBeNull();
});

it('defaults the period to the last payout weekday on or before today', function () {
    Settings::set('payout_weekday', 1); // Monday
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00')); // Wednesday

    expect(GeneratePayoutBatch::defaultPeriodEnd()->toDateString())->toBe('2026-10-19');

    $this->travelTo(CarbonImmutable::parse('2026-10-19 23:00:00')); // Monday itself

    expect(GeneratePayoutBatch::defaultPeriodEnd()->toDateString())->toBe('2026-10-19');
});

it('keeps two lines of one tutor out of the database: one unpaid line per tutor, one line per batch', function () {
    $tutor = pbTutor();
    $line = pbLine($tutor);
    $other = PayoutBatch::factory()->create();

    expect(fn () => PayoutBatchLine::factory()->create(['payout_batch_id' => $other->id, 'tutor_profile_id' => $tutor->id]))->toThrow(QueryException::class);
    expect(fn () => PayoutBatchLine::factory()->create(['payout_batch_id' => $line->payout_batch_id, 'tutor_profile_id' => $tutor->id, 'status' => PayoutLineStatus::Failed, 'failed_at' => now(), 'failure_reason' => 'x']))->toThrow(QueryException::class);
});

// --- Mark paid / failed -----------------------------------------------------------------------------------------

it('marks a line paid with the external reference: ledger entries, buckets, audit', function () {
    $tutor = pbTutor();
    $admin = User::factory()->admin()->create();
    $line = pbLine($tutor, $admin);

    app(MarkPayoutLinePaid::class)($admin, $line, "  BANK   REF 99 \n");

    $line->refresh();

    expect($line->status)->toBe(PayoutLineStatus::Paid)
        ->and($line->external_reference)->toBe('BANK REF 99')
        ->and($line->paid_at)->not->toBeNull()
        ->and($line->resolved_by_user_id)->toBe($admin->id)
        ->and(LedgerEntry::query()->where('payout_id', $line->id)->count())->toBe(2);

    $buckets = app(TutorEarnings::class)->buckets($tutor);

    expect($buckets->paidToDate->toFils())->toBe(22500)
        ->and($buckets->processing->toFils())->toBe(0)
        ->and($buckets->available->toFils())->toBe(0)
        ->and($buckets->total()->toFils())->toBe(app(TutorEarnings::class)->lifetime($tutor)->toFils());

    expect(AuditLog::query()->where('action', 'payout_line.paid')->count())->toBe(1);
    $this->artisan('ledger:verify')->assertExitCode(0);
});

it('refuses to mark a line paid without a reference, and pays nothing', function () {
    $line = pbLine(pbTutor());

    foreach (['', '   ', str_repeat('a', 192)] as $bad) {
        expect(fn () => app(MarkPayoutLinePaid::class)(User::factory()->admin()->create(), $line, $bad))->toThrow(PayoutException::class);
    }

    expect($line->fresh()->status)->toBe(PayoutLineStatus::Pending)
        ->and(LedgerEntry::query()->where('payout_id', $line->id)->exists())->toBeFalse();
});

it('has the database refuse a paid line with no reference', function () {
    $line = pbLine(pbTutor());

    expect(fn () => DB::table('payout_batch_lines')->where('id', $line->id)->update(['status' => 'paid']))->toThrow(QueryException::class);
});

it('refuses to pay a line twice, or to pay a failed one', function () {
    $line = pbLine(pbTutor());
    pbPay($line);

    expect(fn () => pbPay($line, 'AGAIN'))->toThrow(PayoutException::class, 'already paid');
    expect(LedgerEntry::query()->where('payout_id', $line->id)->count())->toBe(2);

    $failed = pbLine(pbTutor());
    app(MarkPayoutLineFailed::class)(User::factory()->admin()->create(), $failed, 'Account closed');

    expect(fn () => pbPay($failed))->toThrow(PayoutException::class, 'already failed');
});

it('marks a line failed with a reason: money back to available, nothing on the ledger', function () {
    $tutor = pbTutor();
    $admin = User::factory()->admin()->create();
    $line = pbLine($tutor, $admin);

    expect(fn () => app(MarkPayoutLineFailed::class)($admin, $line, '  '))->toThrow(PayoutException::class);

    app(MarkPayoutLineFailed::class)($admin, $line, 'IBAN rejected');

    $line->refresh();

    expect($line->status)->toBe(PayoutLineStatus::Failed)
        ->and($line->failure_reason)->toBe('IBAN rejected')
        ->and($line->failed_at)->not->toBeNull()
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Payout->value)->exists())->toBeFalse()
        ->and(pbAvailable($tutor))->toBe(22500)
        ->and(app(TutorEarnings::class)->buckets($tutor)->processing->toFils())->toBe(0);

    expect(fn () => app(MarkPayoutLineFailed::class)($admin, $line, 'again'))->toThrow(PayoutException::class, 'already failed');

    // A failed line is final; the next batch makes a new line, with a new reference.
    $next = pbGenerate($admin)->batch->lines->first();
    expect($next->id)->not->toBe($line->id)->and($next->amount->toFils())->toBe(22500);
});

it('refuses to mark a paid line failed', function () {
    $line = pbLine(pbTutor());
    pbPay($line);

    expect(fn () => app(MarkPayoutLineFailed::class)(User::factory()->admin()->create(), $line, 'oops'))->toThrow(PayoutException::class, 'already paid');
});

it('records a paid transfer even when a lesson in it is disputed afterwards, and flags the tutor', function () {
    $tutor = pbTutor(3);
    $line = pbLine($tutor);
    $lesson = Lesson::query()->where('tutor_profile_id', $tutor->id)->first();

    // The window had closed when the batch was made; a dispute that lands afterwards (forced here, as the dispute actions refuse
    // it past the window) puts the lesson's money on hold while the line is still unpaid.
    Lesson::allowingStatusWrites(fn () => $lesson->forceFill(['status' => LessonStatus::Disputed])->save());
    expect(app(TutorEarnings::class)->buckets($tutor)->available->isNegative())->toBeTrue();

    pbPay($line);

    expect($line->fresh()->status)->toBe(PayoutLineStatus::Paid)
        ->and(LedgerEntry::query()->where('payout_id', $line->id)->count())->toBe(2)
        ->and(app(TutorEarnings::class)->isOverpaid($tutor))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'payout_line.paid')->latest('id')->first()->after['tutor_available_after_fils'])->toBeLessThan(0);

    // The flag is on the tutor's page and on the batch's lines; the next batch skips them while it is negative.
    expect(implode(' ', TutorProfileInfolist::payoutLines($tutor)))->toContain('Negative balance');
    expect(pbGenerate()->batch?->lines()->where('tutor_profile_id', $tutor->id)->exists() ?? false)->toBeFalse();
});

it('cannot change what a line says once it is written, or delete it', function () {
    $line = pbLine(pbTutor());

    expect(fn () => $line->update(['amount' => Money::fils(1)]))->toThrow(LogicException::class)
        ->and(fn () => $line->update(['details' => ['email' => 'x@example.test']]))->toThrow(LogicException::class)
        ->and(fn () => $line->delete())->toThrow(LogicException::class);

    pbPay($line);

    expect(fn () => $line->fresh()->update(['status' => PayoutLineStatus::Failed]))->toThrow(LogicException::class);
});

it('keeps the account details out of serialisation', function () {
    $line = pbLine(pbTutor());

    expect(array_key_exists('details', $line->toArray()))->toBeFalse()
        ->and(DB::table('payout_batch_lines')->where('id', $line->id)->value('details'))->not->toContain('GB00');
});

// --- CSV --------------------------------------------------------------------------------------------------------

it('lists only the lines still to pay, and makes formula-like cells plain text', function () {
    $tutor = pbTutor(3, ['bank_account_name' => '=HYPERLINK("http://x")', 'bank_iban' => '+GB00INJECT0000000000001']);
    $paidTutor = pbTutor(3);
    $batch = pbGenerate()->batch;
    pbPay($batch->lines->firstWhere('tutor_profile_id', $paidTutor->id));

    $export = app(ExportPayoutBatchCsv::class);
    $csv = $export->csv(PayoutMethodCode::BankTransfer, $export->lines($batch, PayoutMethodCode::BankTransfer));

    expect($csv)->toContain("\"'=HYPERLINK(\"\"http://x\"\")\"")
        ->and($csv)->toContain("'+GB00INJECT0000000000001")
        ->and(substr_count($csv, 'TT-'))->toBe(1);
});

it('streams the CSV to an admin and audits the download without its contents', function () {
    $admin = User::factory()->admin()->create();
    pbTutor(3, ['bank_iban' => 'GB00AUDITME000000000001']);
    $batch = pbGenerate($admin)->batch;

    $response = app(ExportPayoutBatchCsv::class)($admin, $batch, PayoutMethodCode::BankTransfer);

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($response->headers->get('content-disposition'))->toContain(sprintf('payout-batch-%05d-bank_transfer.csv', $batch->id))
        ->and($body)->toContain('GB00AUDITME000000000001');

    $audit = AuditLog::query()->where('action', 'payout_batch.exported')->sole();

    expect($audit->after)->toBe(['method' => 'bank_transfer', 'lines' => 1])
        ->and(json_encode($audit->getAttributes()))->not->toContain('GB00AUDITME');
});

// --- Earnings page ----------------------------------------------------------------------------------------------

it('shows the tutor their payout history on the earnings page', function () {
    $tutor = pbTutor();
    $line = pbLine($tutor);
    pbPay($line, 'BANK-PAGE-1');

    $response = test()->actingAs($tutor->user)->get('/tutor/earnings');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('tutor/Earnings')
        ->has('payouts', 1)
        ->where('payouts.0.reference', 'BANK-PAGE-1'));
});

// --- Admin UI ---------------------------------------------------------------------------------------------------

it('generates a batch from the admin list and tells the admin who was left out', function () {
    $admin = User::factory()->admin()->create();
    pbTutor(3);
    pbTutor(3, ['payout_method' => null]);

    Livewire::actingAs($admin)
        ->test(ListPayoutBatches::class)
        ->callAction('generate', ['period_end' => '2026-10-19'])
        ->assertNotified();

    $batch = PayoutBatch::query()->sole();

    expect($batch->lines()->count())->toBe(1)->and($batch->period_end->toDateString())->toBe('2026-10-19');

    Livewire::actingAs($admin)->test(ListPayoutBatches::class)->assertCanSeeTableRecords([$batch]);
});

it('marks lines paid and failed from the batch page, showing no account details', function () {
    $admin = User::factory()->admin()->create();
    $a = pbTutor(3, ['bank_iban' => 'GB00NOSHOW0000000000001']);
    $b = pbTutor(3);
    $batch = pbGenerate($admin)->batch;
    $lineA = $batch->lines->firstWhere('tutor_profile_id', $a->id);
    $lineB = $batch->lines->firstWhere('tutor_profile_id', $b->id);

    $component = Livewire::actingAs($admin)
        ->test(LinesRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => ViewPayoutBatch::class])
        ->assertCanSeeTableRecords([$lineA, $lineB])
        ->assertDontSee('GB00NOSHOW');

    $component->callTableAction('markPaid', $lineA, ['external_reference' => 'UI-REF-1'])->assertHasNoTableActionErrors();
    $component->callTableAction('markFailed', $lineB, ['reason' => 'Account closed'])->assertHasNoTableActionErrors();

    expect($lineA->fresh()->status)->toBe(PayoutLineStatus::Paid)
        ->and($lineB->fresh()->status)->toBe(PayoutLineStatus::Failed);

    $component->assertTableActionHidden('markPaid', $lineA)->assertTableActionHidden('markFailed', $lineB);
});

it('needs a payment reference to mark a line paid from the batch page', function () {
    $admin = User::factory()->admin()->create();
    pbTutor(3);
    $batch = pbGenerate($admin)->batch;
    $line = $batch->lines->first();

    Livewire::actingAs($admin)
        ->test(LinesRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => ViewPayoutBatch::class])
        ->callTableAction('markPaid', $line, ['external_reference' => ''])
        ->assertHasTableActionErrors(['external_reference' => ['required']]);

    expect($line->fresh()->status)->toBe(PayoutLineStatus::Pending);
});

it('lets an admin download one CSV per method from the batch page', function () {
    $admin = User::factory()->admin()->create();
    pbTutor(3);
    $batch = pbGenerate($admin)->batch;

    Livewire::actingAs($admin)
        ->test(ViewPayoutBatch::class, ['record' => $batch->getKey()])
        ->assertActionExists('csv_bank_transfer')
        ->assertActionDoesNotExist('csv_wise')
        ->callAction('csv_bank_transfer')
        ->assertFileDownloaded(sprintf('payout-batch-%05d-bank_transfer.csv', $batch->id));
});

it('is for active admins only', function () {
    $batch = PayoutBatch::factory()->create();

    foreach ([User::factory()->tutor()->create(), User::factory()->create()] as $user) {
        test()->actingAs($user)->get(PayoutBatchResource::getUrl('index'))->assertForbidden();
    }

    expect(PayoutBatchResource::canCreate())->toBeFalse();
    test()->actingAs(User::factory()->admin()->create())->get(PayoutBatchResource::getUrl('view', ['record' => $batch]))->assertOk();
});

// --- R258: only money past its dispute window goes in a batch --------------------------------------------------------

/** A tutor with one released lesson of AED 250.00 tutor share that ended `$hoursAgo` hours ago (over the AED 200.00 minimum). */
function pbWindowTutor(int $hoursAgo, bool $suspensionSettled = false): TutorProfile
{
    $tutor = TutorProfile::factory()->approved()->create([
        'country' => 'AE',
        'payout_method' => 'bank_transfer',
        'user_id' => User::factory()->tutor(),
    ]);
    $lesson = Lesson::factory()->withStatus(LessonStatus::Completed)->startingAt(now()->subHours($hoursAgo + 1))
        ->create(['tutor_profile_id' => $tutor->id, 'price' => Money::fils(33334), 'commission_pct' => 25, 'commission_amount' => Money::fils(8334), 'tutor_amount' => Money::fils(25000)]);
    app(LedgerService::class)->hold($lesson);
    app(LedgerService::class)->release($lesson);
    Lesson::allowingStatusWrites(fn () => $lesson->forceFill(['status' => LessonStatus::CompletedReported])->save());

    if ($suspensionSettled) {
        AuditLog::query()->create(['actor_user_id' => null, 'action' => SettleSuspendedLesson::AUDIT_SETTLED, 'subject_type' => Lesson::class, 'subject_id' => $lesson->id]);
    }

    return $tutor;
}

it('leaves a lesson released 47 hours after its end out of the batch, and takes it at 49', function () {
    $this->freezeTime();
    $tutor = pbWindowTutor(hoursAgo: 47);

    expect(pbAvailable($tutor))->toBe(0)
        ->and(app(TutorEarnings::class)->buckets($tutor)->pending->toFils())->toBe(25000);
    expect(pbGenerate()->batch?->lines()->where('tutor_profile_id', $tutor->id)->exists() ?? false)->toBeFalse();

    $this->travel(2)->hours();

    expect(pbAvailable($tutor))->toBe(25000);
    expect(pbGenerate()->batch?->lines()->where('tutor_profile_id', $tutor->id)->first()->amount->toFils())->toBe(25000);
});

it('makes a lesson that was settled after a suspension wait seven days', function () {
    $this->freezeTime();
    $tutor = pbWindowTutor(hoursAgo: 100, suspensionSettled: true);

    expect(pbAvailable($tutor))->toBe(0);
    expect(pbGenerate()->batch?->lines()->where('tutor_profile_id', $tutor->id)->exists() ?? false)->toBeFalse();

    $this->travel(69)->hours(); // 169 hours after the lesson ended

    expect(pbAvailable($tutor))->toBe(25000);
});

it('never puts a disputed lesson in a batch, whatever its age', function () {
    $tutor = pbWindowTutor(hoursAgo: 500);
    $lesson = Lesson::query()->where('tutor_profile_id', $tutor->id)->firstOrFail();
    Lesson::allowingStatusWrites(fn () => $lesson->forceFill(['status' => LessonStatus::Disputed])->save());

    expect(pbAvailable($tutor))->toBe(0);
    expect(pbGenerate()->batch?->lines()->where('tutor_profile_id', $tutor->id)->exists() ?? false)->toBeFalse();
});
