<?php

use App\Actions\Admin\AnonymizeUser;
use App\Actions\Admin\ClearAnonymisedTutorBankDetails;
use App\Actions\Payouts\ErasePayoutLineDetails;
use App\Actions\Payouts\ExportPayoutBatchCsv;
use App\Actions\Payouts\GeneratePayoutBatch;
use App\Actions\Payouts\MarkPayoutLineFailed;
use App\Actions\Payouts\MarkPayoutLinePaid;
use App\Enums\LessonStatus;
use App\Enums\PayoutLineStatus;
use App\Enums\PayoutMethodCode;
use App\Exceptions\PayoutException;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\PayoutBatchLine;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 26b (R274): a tutor's payout lines lose their encrypted `details` copy with the account; nothing else on a line changes
 * and no line is deleted.
 */
const PLD_IBAN = 'GB00ERASE0000000000042';

/** A tutor with bank details; with `$lessons` released lessons the tutor is owed money (a first batch would pay AED 75.00 each). */
function pldTutor(int $lessons = 0, array $profile = []): TutorProfile
{
    static $slot = 0;

    $tutor = TutorProfile::factory()->approved()->create($profile + [
        'country' => 'AE',
        'payout_method' => 'bank_transfer',
        'bank_account_name' => 'Erase Tutor',
        'bank_iban' => PLD_IBAN,
        'bank_swift' => 'ERASGB2L',
        'bank_verified_at' => now(),
        'user_id' => User::factory()->tutor(),
    ]);

    for ($i = 0; $i < $lessons; $i++) {
        $lesson = Lesson::factory()->withStatus(LessonStatus::Completed)->startingAt(now()->subDays(60)->startOfHour()->addHours($slot++))->create(['tutor_profile_id' => $tutor->id]);
        app(LedgerService::class)->hold($lesson);
        app(LedgerService::class)->release($lesson);
        Lesson::allowingStatusWrites(fn () => $lesson->forceFill(['status' => LessonStatus::CompletedReported])->save());
    }

    return $tutor;
}

function pldLine(TutorProfile $tutor, PayoutLineStatus $status = PayoutLineStatus::Pending, array $state = []): PayoutBatchLine
{
    $paid = $status === PayoutLineStatus::Paid ? ['external_reference' => 'BANK-TEST-'.fake()->unique()->numerify('####'), 'paid_at' => now()] : [];

    return PayoutBatchLine::factory()->create($state + $paid + ['tutor_profile_id' => $tutor->id, 'status' => $status]);
}

function pldDetails(PayoutBatchLine $line): ?string
{
    return DB::table('payout_batch_lines')->where('id', $line->id)->value('details');
}

it('erases only the paid lines when money is still owed, and keeps everything else on them', function () {
    $admin = User::factory()->admin()->create();
    $tutor = pldTutor(lessons: 3);
    $paid = pldLine($tutor, PayoutLineStatus::Paid, ['external_reference' => 'BANK-REF-77', 'paid_at' => now()->subDay()]);
    $pending = pldLine($tutor);
    $failed = pldLine($tutor, PayoutLineStatus::Failed, ['failed_at' => now(), 'failure_reason' => 'Account closed']);
    $other = pldLine(pldTutor(), PayoutLineStatus::Paid);

    app(AnonymizeUser::class)($admin, $tutor->user);

    $paidAfter = PayoutBatchLine::query()->find($paid->id);
    expect($paidAfter->details)->toBeNull()
        ->and($paidAfter->details_erased_at)->not->toBeNull()
        ->and($paidAfter->amount->toFils())->toBe($paid->amount->toFils())
        ->and($paidAfter->payout_method)->toBe($paid->payout_method)
        ->and($paidAfter->currency)->toBe('AED')
        ->and($paidAfter->reference)->toBe($paid->reference)
        ->and($paidAfter->external_reference)->toBe('BANK-REF-77')
        ->and($paidAfter->paid_at)->not->toBeNull()
        ->and(pldDetails($paid))->toBeNull();

    // Unpaid lines keep their copy until the admin's R268 decision; another tutor's line is never touched.
    expect(PayoutBatchLine::query()->find($pending->id)->details)->toBe($pending->details)
        ->and(PayoutBatchLine::query()->find($failed->id)->details)->toBe($failed->details)
        ->and(PayoutBatchLine::query()->find($other->id)->details)->toBe($other->details)
        ->and(PayoutBatchLine::query()->count())->toBe(4);

    $audit = AuditLog::query()->where('action', 'user.anonymized')->sole();
    expect($audit->after['payout_lines_erased'])->toBe(1)
        ->and(json_encode($audit->toArray()))->not->toContain(PLD_IBAN)->not->toContain('GB00TEST');
});

it('erases the copy on every line, failed included, when nothing is owed', function () {
    $admin = User::factory()->admin()->create();
    $tutor = pldTutor();
    // A pending line is money in transit (`processing`), which counts as owed, so with nothing owed there is none.
    $lines = [pldLine($tutor, PayoutLineStatus::Paid), pldLine($tutor, PayoutLineStatus::Failed, ['failed_at' => now(), 'failure_reason' => 'Account closed'])];

    app(AnonymizeUser::class)($admin, $tutor->user);

    foreach ($lines as $line) {
        expect(PayoutBatchLine::query()->find($line->id)->details)->toBeNull();
    }

    expect(AuditLog::query()->where('action', 'user.anonymized')->sole()->after['payout_lines_erased'])->toBe(2)
        ->and(PayoutBatchLine::query()->whereNotNull('details_erased_at')->count())->toBe(2);
});

it('records no erased lines for an account that is not a tutor', function () {
    $admin = User::factory()->admin()->create();
    $parent = User::factory()->create();

    app(AnonymizeUser::class)($admin, $parent);

    expect(AuditLog::query()->where('action', 'user.anonymized')->sole()->after['payout_lines_erased'])->toBeNull();
});

it('erases the remaining copies when the admin records the decision, including a tutor paid by email', function () {
    $admin = User::factory()->admin()->create();
    $tutor = pldTutor(lessons: 3, profile: [
        'payout_method' => 'wise', 'payout_email' => 'wise.tutor@example.test',
        'bank_name' => null, 'bank_account_name' => null, 'bank_iban' => null, 'bank_swift' => null, 'bank_verified_at' => null,
    ]);
    $pending = pldLine($tutor, PayoutLineStatus::Pending, ['payout_method' => PayoutMethodCode::Wise->value, 'details' => ['email' => 'wise.tutor@example.test']]);

    app(AnonymizeUser::class)($admin, $tutor->user);
    $tutor = $tutor->fresh();

    // The profile holds no bank field at all, yet the decision still applies: the line carries the copy.
    expect(PayoutBatchLine::query()->find($pending->id)->details)->not->toBeNull()
        ->and(ClearAnonymisedTutorBankDetails::applies($tutor))->toBeTrue();

    expect(app(ClearAnonymisedTutorBankDetails::class)($admin, $tutor, 'Paid by hand through Wise.'))->toBeTrue();

    $line = PayoutBatchLine::query()->find($pending->id);
    expect($line->details)->toBeNull()
        ->and($line->details_erased_at)->not->toBeNull()
        ->and($line->status)->toBe(PayoutLineStatus::Pending)
        ->and($line->reference)->toBe($pending->reference)
        ->and(ClearAnonymisedTutorBankDetails::applies($tutor->fresh()))->toBeFalse()
        ->and(app(ClearAnonymisedTutorBankDetails::class)($admin, $tutor->fresh(), 'again'))->toBeFalse();

    $audit = AuditLog::query()->where('action', 'tutor.bank_details_cleared')->sole();
    expect($audit->after['payout_lines_erased'])->toBe(1)
        ->and($audit->after['fields_cleared'])->toBe([])
        ->and(json_encode($audit->toArray()))->not->toContain('wise.tutor');
});

it('erases the line copies alongside the bank fields on the decision, and a second erase is a no-op', function () {
    $admin = User::factory()->admin()->create();
    $tutor = pldTutor(lessons: 3);
    $line = pldLine($tutor);
    app(AnonymizeUser::class)($admin, $tutor->user);

    expect(app(ClearAnonymisedTutorBankDetails::class)($admin, $tutor->fresh(), 'Paid by hand.'))->toBeTrue();

    $audit = AuditLog::query()->where('action', 'tutor.bank_details_cleared')->sole();
    expect($audit->after['payout_lines_erased'])->toBe(1)
        ->and($audit->after['fields_cleared'])->toContain('bank_iban')
        ->and(PayoutBatchLine::query()->find($line->id)->details)->toBeNull();

    $erasedAt = PayoutBatchLine::query()->find($line->id)->details_erased_at;
    $count = DB::transaction(fn () => app(ErasePayoutLineDetails::class)($tutor->fresh(), PayoutLineStatus::cases()));

    expect($count)->toBe(0)
        ->and(PayoutBatchLine::query()->find($line->id)->details_erased_at->equalTo($erasedAt))->toBeTrue();
});

it('erases the copy as soon as a line is paid after the tutor was erased, and leaves a live tutor alone', function () {
    $admin = User::factory()->admin()->create();
    $erased = pldTutor(lessons: 3);
    $live = pldTutor(lessons: 3);
    $batch = app(GeneratePayoutBatch::class)($admin)->batch;
    $erasedLine = $batch->lines()->where('tutor_profile_id', $erased->id)->sole();
    $liveLine = $batch->lines()->where('tutor_profile_id', $live->id)->sole();

    app(AnonymizeUser::class)($admin, $erased->user);

    // Money was owed, so the pending line kept its copy for the transfer.
    expect(PayoutBatchLine::query()->find($erasedLine->id)->details)->not->toBeNull();

    app(MarkPayoutLinePaid::class)($admin, $erasedLine, 'BANK-REF-1');
    app(MarkPayoutLinePaid::class)($admin, $liveLine, 'BANK-REF-2');

    $paid = PayoutBatchLine::query()->find($erasedLine->id);
    expect($paid->status)->toBe(PayoutLineStatus::Paid)
        ->and($paid->details)->toBeNull()
        ->and($paid->details_erased_at)->not->toBeNull()
        ->and($paid->external_reference)->toBe('BANK-REF-1')
        ->and($paid->amount->toFils())->toBe(22500)
        ->and(PayoutBatchLine::query()->find($liveLine->id)->details)->not->toBeNull()
        ->and(PayoutBatchLine::query()->find($liveLine->id)->details_erased_at)->toBeNull();

    expect(AuditLog::query()->where('action', 'payout_line.paid')->where('subject_id', $erasedLine->id)->sole()->after['details_erased'])->toBeTrue()
        ->and(AuditLog::query()->where('action', 'payout_line.paid')->where('subject_id', $liveLine->id)->sole()->after['details_erased'])->toBeFalse();

    // The ledger is untouched by the erasure.
    expect(app(LedgerService::class)->unbalancedPayouts())->toBeEmpty()
        ->and(app(LedgerService::class)->unbalancedLessons())->toBeEmpty();
});

it('lets the admin mark an erased pending line failed, and keeps the copy erased', function () {
    $admin = User::factory()->admin()->create();
    $tutor = pldTutor(lessons: 3);
    $line = app(GeneratePayoutBatch::class)($admin)->batch->lines()->sole();
    app(AnonymizeUser::class)($admin, $tutor->user);

    // Marking failed needs no change: it never reads the details, and it does not erase them before the decision.
    expect(PayoutBatchLine::query()->find($line->id)->details)->not->toBeNull();

    app(ClearAnonymisedTutorBankDetails::class)($admin, $tutor->fresh(), 'Will pay by hand.');
    app(MarkPayoutLineFailed::class)($admin, $line->fresh(), 'Account details erased');

    $after = PayoutBatchLine::query()->find($line->id);
    expect($after->status)->toBe(PayoutLineStatus::Failed)
        ->and($after->details)->toBeNull()
        ->and($after->failure_reason)->toBe('Account details erased');
});

it('keeps an unpaid line\'s details when it is marked failed before the decision', function () {
    $admin = User::factory()->admin()->create();
    $tutor = pldTutor(lessons: 3);
    $line = app(GeneratePayoutBatch::class)($admin)->batch->lines()->sole();
    app(AnonymizeUser::class)($admin, $tutor->user);

    app(MarkPayoutLineFailed::class)($admin, $line->fresh(), 'Bank rejected it');

    expect(PayoutBatchLine::query()->find($line->id)->details)->not->toBeNull();
});

it('refuses to export a pending line whose details were erased, naming it, and still exports the others', function () {
    $admin = User::factory()->admin()->create();
    $erased = pldTutor(lessons: 3);
    $kept = pldTutor(lessons: 3);
    $batch = app(GeneratePayoutBatch::class)($admin)->batch;
    app(AnonymizeUser::class)($admin, $erased->user);
    app(ClearAnonymisedTutorBankDetails::class)($admin, $erased->fresh(), 'Will pay by hand.');

    $erasedLine = $batch->lines()->where('tutor_profile_id', $erased->id)->sole();
    $keptLine = $batch->lines()->where('tutor_profile_id', $kept->id)->sole();
    $export = app(ExportPayoutBatchCsv::class);

    $lines = $export->lines($batch, PayoutMethodCode::BankTransfer);
    expect(fn () => $export->csv(PayoutMethodCode::BankTransfer, $lines))
        ->toThrow(PayoutException::class, $erasedLine->reference);

    app(MarkPayoutLineFailed::class)($admin, $erasedLine->fresh(), 'Account details erased');

    $csv = $export->csv(PayoutMethodCode::BankTransfer, $export->lines($batch, PayoutMethodCode::BankTransfer));
    expect($csv)->toContain($keptLine->reference)->not->toContain($erasedLine->reference);
});

it('refuses to erase outside a transaction', function () {
    DB::rollBack(0);

    expect(fn () => app(ErasePayoutLineDetails::class)(new TutorProfile, PayoutLineStatus::cases()))
        ->toThrow(LogicException::class, 'transaction');
});

it('lets the database refuse anything but erasing the details', function () {
    $line = pldLine(pldTutor());

    // Rewriting a copy is refused by the trigger, even for a raw statement.
    expect(fn () => DB::transaction(fn () => DB::table('payout_batch_lines')->where('id', $line->id)->update(['details' => 'rewritten'])))
        ->toThrow(QueryException::class, 'can only be erased');

    // A null without its erased-at stamp, and a stamp on a line that still has details, are both refused.
    expect(fn () => DB::transaction(fn () => DB::table('payout_batch_lines')->where('id', $line->id)->update(['details' => null])))
        ->toThrow(QueryException::class, 'payout_batch_lines_details_erased_consistent')
        ->and(fn () => DB::transaction(fn () => DB::table('payout_batch_lines')->where('id', $line->id)->update(['details_erased_at' => now()])))
        ->toThrow(QueryException::class, 'payout_batch_lines_details_erased_consistent');

    // Erasing is allowed; once erased the copy cannot be put back.
    DB::table('payout_batch_lines')->where('id', $line->id)->update(['details' => null, 'details_erased_at' => now()]);

    expect(pldDetails($line))->toBeNull()
        ->and(fn () => DB::transaction(fn () => DB::table('payout_batch_lines')->where('id', $line->id)->update(['details' => 'back again', 'details_erased_at' => null])))
        ->toThrow(QueryException::class, 'can only be erased');

    // Other columns still change as before.
    DB::table('payout_batch_lines')->where('id', $line->id)->update(['status' => 'failed', 'failure_reason' => 'x', 'failed_at' => now()]);
    expect(PayoutBatchLine::query()->find($line->id)->status)->toBe(PayoutLineStatus::Failed);
});
