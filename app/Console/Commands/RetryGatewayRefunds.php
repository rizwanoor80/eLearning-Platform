<?php

namespace App\Console\Commands;

use App\Actions\Payments\ReconcileUnrecordedPayments;
use App\Actions\Payments\RecordExternalRefunds;
use App\Actions\Payments\RefundParentAtGateway;
use App\Actions\Payments\RefundUnverifiedCapture;
use App\Enums\LedgerAccount;
use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentNeedsAttention;
use App\Models\LedgerEntry;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentRefund;
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

    /** 25b (R267): how long a refund the gateway reports `pending` may stay that way before admins are told. */
    public const STUCK_HOURS = 72;

    protected $signature = 'payments:retry-refunds {--list : Only list refunds the gateway has refused, reports pending, or has not yet confirmed}';

    protected $description = 'Ask the payment gateway for refunds the ledger has recorded and the gateway has not confirmed (23d, R256)';

    /**
     * The durability net under the listener (R256): the ledger is the source of truth, so a refund entry
     * with no confirmed gateway refund is found here whatever happened to the queued job. Idempotent: the
     * action keys each gateway refund on its ledger entry and records what it made, so a second run finds
     * nothing left (and an overlapping one is stopped by `onOneServer()->withoutOverlapping()`). A refusal
     * is permanent and only listed; it is never retried here.
     */
    public function handle(RefundParentAtGateway $refund, RefundUnverifiedCapture $refundUnverified, ReconcileUnrecordedPayments $reconcile, RecordExternalRefunds $recordExternal): int
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
                    PaymentNeedsAttention::dispatch($payment, PaymentNeedsAttention::UNVERIFIED_REFUND_FAILED);
                }
            }
        });

        // 25b (R267): a refund Stripe reports `pending` that no ledger entry explains (one made in the dashboard) is
        // settled by listing the gateway's refunds; one stuck for days is reported once a day.
        $this->alertStuck();

        $pending = $this->pendingAtGateway()->each(function (Payment $payment) use ($recordExternal): void {
            try {
                // The same word to admins as the reconcile job gives: a dashboard refund the sweep records first is not silent.
                if (($recordExternal($payment) ?? 0) > 0) {
                    PaymentNeedsAttention::dispatch($payment, PaymentNeedsAttention::REFUNDED_AT_GATEWAY);
                }
            } catch (Throwable $e) {
                report($e);
            }
        })->count();

        $this->info("Made {$made} gateway refund(s).");
        $this->info("Checked {$pending} payment(s) with a refund still pending at the gateway.");
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

            foreach ($refund->owed($lesson) as $entry) {
                // R277: an entry whose refund the gateway holds pending is listed once, below, with its gateway status.
                if (PaymentRefund::query()->where('ledger_entry_id', $entry->id)->where('status', PaymentRefundStatus::Requested->value)->whereNotNull('gateway_refund_id')->exists()) {
                    continue;
                }

                $rows[] = [$lesson->id, $payment->id, $entry->id, $entry->amount, 'not yet confirmed'];
            }
        });

        PaymentRefund::query()
            ->where('status', PaymentRefundStatus::Failed->value)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('payment_refunds as later')
                ->whereColumn('later.payment_id', 'payment_refunds.payment_id')
                ->whereRaw('later.kind = payment_refunds.kind')
                ->whereRaw('later.ledger_entry_id is not distinct from payment_refunds.ledger_entry_id')
                ->whereColumn('later.attempt', '>', 'payment_refunds.attempt'))
            ->with('payment')
            ->orderBy('id')
            ->each(function (PaymentRefund $failed) use (&$rows): void {
                // A refund someone else made at the gateway that then failed is information, not a refusal of the platform's.
                $label = $failed->kind === PaymentRefundKind::External ? 'failed at gateway (not the platform\'s refund)' : 'refused';
                $rows[] = [$failed->payment->lesson_id, $failed->payment_id, $failed->ledger_entry_id ?? '-', $failed->amount->toFils(), $label.': '.($failed->failure_reason ?? 'unknown')];
            });

        // 25b (R267): refunds the gateway has accepted but reports `pending` are not yet money returned.
        PaymentRefund::query()
            ->where('status', PaymentRefundStatus::Requested->value)
            ->whereNotNull('gateway_refund_id')
            ->with('payment')
            ->orderBy('id')
            ->each(function (PaymentRefund $pending) use (&$rows): void {
                $rows[] = [$pending->payment->lesson_id, $pending->payment_id, $pending->ledger_entry_id ?? '-', $pending->amount->toFils(), 'pending at gateway ('.($pending->gateway_status ?? 'unknown').')'];
            });

        $this->table(['Lesson', 'Payment', 'Ledger entry', 'Amount (fils)', 'State'], $rows);

        return self::SUCCESS;
    }

    /**
     * Payments with an external refund still `requested` (Stripe reported it `pending`) old enough to be the sweep's.
     * The platform's own `requested` rows are re-asked by the ledger and unverified paths above, by the same key.
     *
     * @return LazyCollection<int, Payment>
     */
    private function pendingAtGateway(): LazyCollection
    {
        return Payment::query()
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('payment_refunds as r')
                ->whereColumn('r.payment_id', 'payments.id')
                ->where('r.kind', PaymentRefundKind::External->value)
                ->where('r.status', PaymentRefundStatus::Requested->value)
                ->where('r.requested_at', '<=', now()->subMinutes(self::GRACE_MINUTES)))
            ->orderBy('id')
            ->lazyById();
    }

    /**
     * Refunds the gateway holds (an id is stored) that have stayed `requested` past {@see self::STUCK_HOURS}: a card
     * refund that takes days is Stripe's to finish, but one that never does is for an admin. Alerted once a day.
     */
    private function alertStuck(): void
    {
        PaymentRefund::query()
            ->where('status', PaymentRefundStatus::Requested->value)
            ->whereNotNull('gateway_refund_id')
            ->where('requested_at', '<=', now()->subHours(self::STUCK_HOURS))
            ->with('payment')
            ->orderBy('id')
            ->each(function (PaymentRefund $row): void {
                if (Cache::add("refund-stuck-alert:{$row->id}", true, now()->addDay())) {
                    PaymentNeedsAttention::dispatch($row->payment, PaymentNeedsAttention::REFUND_STUCK);
                }
            });
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
            // Settled (succeeded, or refused with no newer attempt) or in flight (a `requested` row the queue is
            // still working on) payments are not the sweep's.
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('payment_refunds as r')
                ->whereColumn('r.payment_id', 'payments.id')
                ->where('r.kind', PaymentRefundKind::Unverified->value)
                ->where(fn ($q) => $q->where('r.status', PaymentRefundStatus::Succeeded->value)
                    ->orWhere(fn ($failed) => $failed->where('r.status', PaymentRefundStatus::Failed->value)
                        ->whereRaw("r.attempt = (select max(m.attempt) from payment_refunds m where m.payment_id = r.payment_id and m.kind = 'unverified')"))
                    ->orWhere(fn ($open) => $open->where('r.status', PaymentRefundStatus::Requested->value)
                        ->where('r.requested_at', '>', now()->subMinutes(self::GRACE_MINUTES)))))
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
                    ->whereNotNull('p.gateway_ref');
            })
            // Settled (succeeded, or refused with no newer attempt) or in flight (a `requested` row the queue is
            // still working on) entries are not the sweep's.
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('payment_refunds as r')
                    ->whereColumn('r.ledger_entry_id', 'e.id')
                    ->where(fn ($q) => $q->where('r.status', PaymentRefundStatus::Succeeded->value)
                        ->orWhere(fn ($failed) => $failed->where('r.status', PaymentRefundStatus::Failed->value)
                            ->whereRaw('r.attempt = (select max(m.attempt) from payment_refunds m where m.ledger_entry_id = r.ledger_entry_id)'))
                        ->orWhere(fn ($open) => $open->where('r.status', PaymentRefundStatus::Requested->value)
                            ->where('r.requested_at', '>', now()->subMinutes(self::GRACE_MINUTES))));
            })
            ->distinct()
            ->pluck('e.lesson_id');

        return Lesson::query()->whereIn('id', $lessonIds)->orderBy('id')->lazyById();
    }
}
