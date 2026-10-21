<?php

namespace App\Enums;

enum PaymentRefundStatus: string
{
    /** Recorded before the gateway is asked; also what a run that died mid-call leaves behind. */
    case Requested = 'requested';

    /** The gateway accepted the refund (it can still be `pending` on the card network; see `gateway_status`). */
    case Succeeded = 'succeeded';

    /** The gateway refused it, or reported it failed or canceled. Terminal until an admin re-queues it. */
    case Failed = 'failed';
}
