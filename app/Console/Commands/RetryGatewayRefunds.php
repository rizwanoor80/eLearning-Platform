<?php

namespace App\Console\Commands;

use App\Actions\Payments\ReconcileUnrecordedPayments;
use App\Actions\Payments\RefundParentAtGateway;
use App\Actions\Payments\RefundUnverifiedCapture;
use App\Enums\LedgerAccount;
use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\LazyCollection;
use Throwable;

class RetryGatewayRefunds extends Command
{
    /**
     * A refund younger than this is still the queue's to make; the sweep only picks up what the queue
     * lost or gave up on.
     */
    public const GRACE_MINUTES = 10;

    protected $signature = 'payments:retry-refunds {--list : Only list refunds the gateway has refused or has not yet confirmed}';

    protected $description = 'Ask the payment gateway for refunds the ledger has recorded and the gateway has not confirmed (23d, R256)';

    /**
     * The durability net under the listener (R256): the ledger is the source of truth, so a refund entry
     * with no confirmed gateway refund is found here whatever happened to the queued job. Idempotent: the
     * action keys each gateway refund on its ledger entry and records what it made, so a second run finds
     * nothing left (and an overlapping one is stopped by `onOneServer()->withoutOverlapping()`). A refusal
     * is permanent and only listed; it is never retried here.
     */
    public function handle(RefundParentAtGateway $refund, RefundUnverifiedCapture $refundUnverified, ReconcileUnrecordedPayments $reconcile): int
    {
        if ($this->option('list')) {
            return $this->list($refund);
        }

        $made = 0;

        $this->lessonsOwed()->each(function (Lesson $lesson) use ($refund, &$made): void {
            try {
                $made += $refund($lesson);
            } catch (Throwable $e) {
                report($e);

                $payment = $lesson->payment()->first();

                // One alert per payment a day: a lasting outage must not mail admins every hour.
                if ($payment !== null && Cache::add("refund-sweep-alert:{$payment->id}", true, now()->addDay())) {
                    PaymentNeedsAttention::dispatch($payment, PaymentNeedsAttention::REFUND_FAILED);
                }
            }
        });

        // R259 (5): payments a dead booking request recorded but never finished, settled first so a
        // capture it found is refunded in the same run.
        $settled = $reconcile();

        // R259 (2): a captured payment with no ledger entry at all is a booking that could not be confirmed.
        $this->unverifiedCaptures()->each(function (Payment $payment) use ($refundUnverified, &$made): void {
            try {
                $made += $refundUnverified($payment) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);

                if (Cache::add("refund-sweep-alert:{$payment->id}", true, now()->addDay())) {
                    PaymentNeedsAttention::dispatch($payment, PaymentNeedsAttention::REFUND_FAILED);
                }
            }
        });

        $this->info("Made {$made} gateway refund(s).");
        $this->info("Settled {$settled} unrecorded payment(s).");

        return self::SUCCESS;
    }

    private function list(RefundParentAtGateway $refund): int
    {
        $rows = [];

        $this->lessonsOwed()->each(function (Lesson $lesson) use ($refund, &$rows): void {
            $payment = $lesson->payment()->first();

            if ($payment === null) {
                return;
            }

            foreach ($refund->owed($lesson, $payment) as $entry) {
                $rows[] = [$lesson->id, $payment->id, $entry->id, $entry->amount, 'not yet confirmed'];
            }
        });

        Payment::query()->whereNotNull('raw_response')->whereRaw("jsonb_exists(raw_response::jsonb, 'refund_failures')")->orderBy('id')->each(function (Payment $payment) use (&$rows): void {
            foreach ($payment->raw_response['refund_failures'] ?? [] as $entryId => $failure) {
                $rows[] = [$payment->lesson_id, $payment->id, $entryId, LedgerEntry::query()->whereKey($entryId)->value('amount'), 'refused: '.($failure['code'] ?? 'unknown')];
            }
        });

        $this->table(['Lesson', 'Payment', 'Ledger entry', 'Amount (fils)', 'State'], $rows);

        return self::SUCCESS;
    }

    /**
     * Captured payments the platform never held: no ledger entry for their lesson, old enough that the
     * queued refund has had its chance, and not yet refunded or refused.
     *
     * @return LazyCollection<int, Payment>
     */
    private function unverifiedCaptures(): LazyCollection
    {
        return Payment::query()
            ->where('status', PaymentStatus::Captured->value)
            ->whereNotNull('gateway_ref')
            ->where('updated_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('ledger_entries as e')->whereColumn('e.lesson_id', 'payments.lesson_id'))
            ->whereRaw("coalesce(raw_response::jsonb->'unverified_refund'->>'status', '') not in ('succeeded', 'refused')")
            ->orderBy('id')
            ->lazyById();
    }

    /**
     * Lessons with a refund entry old enough to be the sweep's, on a payment that has neither confirmed
     * nor been refused that entry.
     *
     * @return LazyCollection<int, Lesson>
     */
    private function lessonsOwed(): LazyCollection
    {
        $lessonIds = LedgerEntry::query()
            ->from('ledger_entries as e')
            ->where('e.account', LedgerAccount::Refund->value)
            ->where('e.amount', '>', 0)
            ->where('e.created_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('payments as p')
                    ->whereColumn('p.lesson_id', 'e.lesson_id')
                    ->whereNotNull('p.gateway_ref')
                    ->whereRaw("not jsonb_exists(coalesce(p.raw_response::jsonb->'refunds', '{}'::jsonb), e.id::text)")
                    ->whereRaw("not jsonb_exists(coalesce(p.raw_response::jsonb->'refund_failures', '{}'::jsonb), e.id::text)");
            })
            ->distinct()
            ->pluck('e.lesson_id');

        return Lesson::query()->whereIn('id', $lessonIds)->orderBy('id')->lazyById();
    }
}
