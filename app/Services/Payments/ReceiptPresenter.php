<?php

namespace App\Services\Payments;

use App\Enums\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;

/**
 * R257: shapes one payment as a parent's receipt. Reads only; nothing here writes money or lesson state.
 * The amount is what the gateway charged; refund lines come from the ledger's Refund-account entries
 * (the ledger decides what is owed back) and are marked "returned" once the gateway confirmed the refund
 * (`raw_response.refunds.<entry id>`, written by 23d). No VAT line: D-04 has not decided whether or how
 * VAT applies.
 */
class ReceiptPresenter
{
    private const DATE_FORMAT = 'D, j M Y, g:i A';

    /**
     * The list row: enough to recognise the payment and open its receipt.
     *
     * @return array<string, mixed>
     */
    public function row(Payment $payment, User $viewer): array
    {
        $lesson = $payment->lesson;

        return [
            'id' => $payment->id,
            'paid_on' => $payment->created_at->setTimezone($viewer->timezone)->format('j M Y'),
            'lesson_on' => $lesson->starts_at->setTimezone($viewer->timezone)->format('j M Y'),
            'tutor_display_name' => $lesson->tutorProfile->displayName(),
            'learner_display_name' => $lesson->learner->display_name,
            'amount' => $payment->amount->format($payment->currency),
            'refunded' => $payment->refunded_amount !== null && ! $payment->refunded_amount->isZero()
                ? $payment->refunded_amount->format($payment->currency)
                : null,
            'reference' => $payment->gateway_ref,
        ];
    }

    /**
     * The printable receipt for one payment.
     *
     * @return array<string, mixed>
     */
    public function receipt(Payment $payment, User $viewer): array
    {
        $lesson = $payment->lesson;
        $refunds = $this->refundLines($payment);
        $net = $payment->amount;

        foreach ($refunds as $line) {
            $net = $net->subtract($line['amount']);
        }

        return [
            ...$this->row($payment, $viewer),
            'number' => 'R-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
            'paid_at' => $payment->created_at->setTimezone($viewer->timezone)->format(self::DATE_FORMAT),
            'lesson_at' => $lesson->starts_at->setTimezone($viewer->timezone)->format(self::DATE_FORMAT),
            'duration_minutes' => $lesson->duration_minutes,
            'subject' => $lesson->subject?->name,
            'timezone' => $viewer->timezone,
            'currency' => $payment->currency,
            'payer_name' => $viewer->name,
            'refunds' => array_map(fn (array $line): array => [
                'date' => $line['entry']->created_at->setTimezone($viewer->timezone)->format('j M Y'),
                'amount' => $line['amount']->format($payment->currency),
                'returned' => $line['returned'],
            ], $refunds),
            // A refund can never return more than was paid (23d caps each at the payment), but never print a negative total.
            'net' => ($net->isNegative() ? Money::zero() : $net)->format($payment->currency),
        ];
    }

    /**
     * @return list<array{entry: LedgerEntry, amount: Money, returned: bool}>
     */
    private function refundLines(Payment $payment): array
    {
        $confirmed = is_array($payment->raw_response['refunds'] ?? null) ? $payment->raw_response['refunds'] : [];

        $lines = [];

        $entries = LedgerEntry::query()
            ->where('lesson_id', $payment->lesson_id)
            ->where('account', LedgerAccount::Refund->value)
            ->where('amount', '>', 0)
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $lines[] = [
                'entry' => $entry,
                'amount' => Money::fils($entry->amount),
                'returned' => array_key_exists((string) $entry->id, $confirmed),
            ];
        }

        return $lines;
    }
}
