<?php

namespace App\Listeners\Payments;

use App\Actions\Payments\RefundParentAtGateway;
use App\Enums\LedgerAccount;
use App\Events\Lessons\LessonStatusChanged;
use App\Events\Payments\PaymentNeedsAttention;
use App\Models\LedgerEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * 23d (R256): every route by which the ledger returns money to a parent — a full refund through
 * `LessonSettlement::refundParent`, a cancellation, the parent share of `ResolveDispute` — runs inside
 * `LessonStateMachine::transition()`, whose `LessonStatusChanged` fires after the commit. This queued
 * listener looks for refund entries the gateway has not yet been asked about and asks. A transient
 * failure is retried by the queue; once the tries run out admins are told, and the hourly
 * `payments:retry-refunds` sweep tries again. The ledger is never reversed.
 */
class RefundParentAtGatewayListener implements ShouldQueue
{
    public int $tries = 5;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(LessonStatusChanged $event): void
    {
        $hasRefund = LedgerEntry::query()
            ->where('lesson_id', $event->lesson->id)
            ->where('account', LedgerAccount::Refund->value)
            ->exists();

        if ($hasRefund) {
            app(RefundParentAtGateway::class)($event->lesson);
        }
    }

    public function failed(LessonStatusChanged $event, Throwable $e): void
    {
        $payment = $event->lesson->payment()->first();

        if ($payment !== null) {
            PaymentNeedsAttention::dispatch($payment, PaymentNeedsAttention::REFUND_FAILED);
        }
    }
}
