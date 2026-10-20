<?php

namespace App\Listeners\Payments;

use App\Events\Payments\PaymentNeedsAttention;
use App\Mail\Admin\AdminPaymentAttentionMail;
use App\Support\Mail\AdminRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * 23b (R247): the `AdminRecipients::resolve()` pattern — the `support_address` setting when set,
 * else every active admin. Queued; never sent in the webhook's own request.
 */
class SendAdminPaymentAttentionMail implements ShouldQueue
{
    /** Dispatched inside the webhook's transaction: the worker must not see the payment before it commits, and a rollback sends nothing. */
    public bool $afterCommit = true;

    public function handle(PaymentNeedsAttention $event): void
    {
        if (($admins = AdminRecipients::resolve()) !== []) {
            Mail::to($admins)->send(new AdminPaymentAttentionMail($event->payment, $event->reason));
        }
    }
}
