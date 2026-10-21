<?php

namespace App\Actions\Payouts;

use App\Actions\RecordAuditLog;
use App\Enums\PayoutLineStatus;
use App\Enums\PayoutMethodCode;
use App\Exceptions\PayoutException;
use App\Models\PayoutBatch;
use App\Models\PayoutBatchLine;
use App\Models\User;
use App\Rules\NotSpreadsheetFormula;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 23h (R251): the file an admin pays from, one per payout method in the batch. A bank transfer file has the account
 * name, IBAN, SWIFT, amount, currency and the platform's reference; every other method has the account email, amount,
 * currency and reference. The amount is a decimal ("123.45"), the currency is the line's own, the reference is the line's
 * `TT-…` reference the admin types into the bank so the payment can be matched back.
 *
 * Only lines still `pending` are in the file: it is what is left to pay. The details come from the line (copied when
 * the batch was generated), never the tutor's current profile.
 *
 * This is the one place account details leave the encrypted columns in clear (invariant: masked everywhere else), so
 * every download is audited, with the method and the number of lines and never a value. Values are written exactly as
 * stored: a bank would read an added `'` as part of the name, so a value that a spreadsheet would run as a formula
 * (`=`, `+`, `-`, `@`) is refused when it is typed (R263). A pending line that still carries one (made before that rule)
 * stops the file with a `PayoutException` naming the line references; the admin marks those lines failed and generates again.
 * The caller must be an active admin, checked here first.
 */
class ExportPayoutBatchCsv
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @throws PayoutException
     */
    public function __invoke(User $admin, PayoutBatch $batch, PayoutMethodCode $method): StreamedResponse
    {
        AssertPayoutAdmin::for($admin, 'download a payout file');

        $lines = $this->lines($batch, $method);
        $csv = $this->csv($method, $lines);

        ($this->recordAuditLog)($admin, 'payout_batch.exported', $batch, null, ['method' => $method->value, 'lines' => count($lines)]);

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, self::filename($batch, $method), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function filename(PayoutBatch $batch, PayoutMethodCode $method): string
    {
        return sprintf('payout-batch-%05d-%s.csv', $batch->id, $method->value);
    }

    /**
     * @return list<PayoutBatchLine>
     */
    public function lines(PayoutBatch $batch, PayoutMethodCode $method): array
    {
        return array_values($batch->lines()
            ->where('payout_method', $method->value)
            ->where('status', PayoutLineStatus::Pending->value)
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * @param  list<PayoutBatchLine>  $lines
     */
    public function csv(PayoutMethodCode $method, array $lines): string
    {
        $unsafe = [];

        foreach ($lines as $line) {
            foreach ((array) $line->details as $value) {
                if (NotSpreadsheetFormula::startsLikeFormula($value)) {
                    $unsafe[] = $line->reference;

                    break;
                }
            }
        }

        if ($unsafe !== []) {
            throw new PayoutException('These lines carry a value that starts with =, +, - or @ and cannot go in a bank file: '.implode(', ', $unsafe).'. Mark them failed and generate again once the tutor has corrected the details.');
        }

        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('Could not open a memory stream for the payout CSV.');

        fputcsv($out, $method->needsBankDetails()
            ? ['Account name', 'IBAN', 'SWIFT', 'Amount', 'Currency', 'Reference']
            : ['Email', 'Amount', 'Currency', 'Reference'], escape: '');

        foreach ($lines as $line) {
            $details = $line->details;

            fputcsv($out, $method->needsBankDetails()
                ? [$details['account_name'] ?? '', $details['iban'] ?? '', $details['swift'] ?? '', $line->amount->toDecimalString(), $line->currency, $line->reference]
                : [$details['email'] ?? '', $line->amount->toDecimalString(), $line->currency, $line->reference], escape: '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
