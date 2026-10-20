<?php

namespace App\Actions\Payouts;

use App\Actions\RecordAuditLog;
use App\Enums\PayoutLineStatus;
use App\Enums\PayoutMethodCode;
use App\Models\PayoutBatch;
use App\Models\PayoutBatchLine;
use App\Models\User;
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
 * every download is audited, with the method and the number of lines and never a value. Cells that a spreadsheet would
 * run as a formula are made plain text (a leading `'`): a tutor chooses their own account name.
 */
class ExportPayoutBatchCsv
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function __invoke(User $admin, PayoutBatch $batch, PayoutMethodCode $method): StreamedResponse
    {
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
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('Could not open a memory stream for the payout CSV.');

        fputcsv($out, $method->needsBankDetails()
            ? ['Account name', 'IBAN', 'SWIFT', 'Amount', 'Currency', 'Reference']
            : ['Email', 'Amount', 'Currency', 'Reference'], escape: '');

        foreach ($lines as $line) {
            $details = $line->details;

            fputcsv($out, array_map(self::plain(...), $method->needsBankDetails()
                ? [$details['account_name'] ?? '', $details['iban'] ?? '', $details['swift'] ?? '', $line->amount->toDecimalString(), $line->currency, $line->reference]
                : [$details['email'] ?? '', $line->amount->toDecimalString(), $line->currency, $line->reference]), escape: '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private static function plain(string $cell): string
    {
        return $cell !== '' && str_contains("=+-@\t\r", $cell[0]) ? "'".$cell : $cell;
    }
}
