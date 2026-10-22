@extends('emails.layout')

@section('content')
    @if ($reason === \App\Events\Payments\PaymentNeedsAttention::DISPUTED_AT_GATEWAY)
        <p>
            A dispute was opened with the card network on payment {{ $payment->id }} (lesson {{ $payment->lesson_id }}).
            Nothing has been changed on the lesson or in the ledger.
        </p>
    @elseif ($reason === \App\Events\Payments\PaymentNeedsAttention::REFUND_FAILED)
        <p>
            The ledger has returned money to the parent for lesson {{ $payment->lesson_id }}, but the payment gateway has not
            refunded it to the card (payment {{ $payment->id }}). A refund that could not be requested is retried automatically;
            one the gateway refused, failed or cancelled is not, and needs you. The ledger has not been changed.
        </p>
    @elseif ($reason === \App\Events\Payments\PaymentNeedsAttention::UNVERIFIED_REFUND_FAILED)
        <p>
            Payment {{ $payment->id }} for lesson {{ $payment->lesson_id }} was taken but the booking could not be confirmed,
            and the automatic refund to the parent's card has not gone through. The ledger was never involved and has not been changed.
            A refund that could not be requested is retried automatically; one the gateway refused, failed or cancelled needs you.
        </p>
    @elseif ($reason === \App\Events\Payments\PaymentNeedsAttention::EXTERNAL_REFUND_FAILED)
        <p>
            A refund made at the payment gateway outside the platform (in its dashboard) on payment {{ $payment->id }}
            (lesson {{ $payment->lesson_id }}) has failed or been cancelled. The platform did not make it and does not retry it.
            Nothing has been changed on the lesson or in the ledger.
        </p>
    @elseif ($reason === \App\Events\Payments\PaymentNeedsAttention::REFUND_STUCK)
        <p>
            A refund on payment {{ $payment->id }} (lesson {{ $payment->lesson_id }}) was accepted by the payment gateway more than
            {{ \App\Console\Commands\RetryGatewayRefunds::STUCK_HOURS }} hours ago and is still pending there, so the money has not
            reached the card. It is the gateway's to finish. If the platform requested this refund, the hourly sweep asks again with the same key. This alert changes nothing on the lesson or in the ledger.
        </p>
    @elseif ($reason === \App\Events\Payments\PaymentNeedsAttention::UNVERIFIED_CAPTURE_REFUNDED)
        <p>
            Payment {{ $payment->id }} for lesson {{ $payment->lesson_id }} was taken but the booking could not be confirmed.
            It has been refunded to the parent's card automatically ({{ ($payment->refunded_amount ?? \App\Support\Money::zero())->format($payment->currency) }}).
            The ledger was never involved and has not been changed.
        </p>
    @else
        <p>
            The payment gateway reported a refund on payment {{ $payment->id }} (lesson {{ $payment->lesson_id }}) that the platform has not fully recorded.
            @if ($payment->refunded_amount !== null && $payment->refunded_amount->toFils() > 0)
                The payment shows {{ $payment->refunded_amount->format($payment->currency) }} refunded in total, whoever made the refunds.
            @endif
            Nothing has been changed on the lesson or in the ledger.
        </p>
    @endif
    <p>Check the payment in the gateway dashboard and decide what to do.</p>
@endsection
