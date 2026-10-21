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
            refunded it to the card (payment {{ $payment->id }}). Failed refunds are retried automatically, except a
            refusal by the gateway, which needs you. The ledger has not been changed.
        </p>
    @elseif ($reason === \App\Events\Payments\PaymentNeedsAttention::UNVERIFIED_CAPTURE_REFUNDED)
        <p>
            Payment {{ $payment->id }} for lesson {{ $payment->lesson_id }} was taken but the booking could not be confirmed.
            It has been refunded to the parent's card automatically ({{ ($payment->refunded_amount ?? \App\Support\Money::zero())->format($payment->currency) }}).
            The ledger was never involved and has not been changed.
        </p>
    @else
        <p>
            The payment gateway has refunded {{ ($payment->refunded_amount ?? \App\Support\Money::zero())->format($payment->currency) }}
            of payment {{ $payment->id }} (lesson {{ $payment->lesson_id }}), which the platform did not record.
            Nothing has been changed on the lesson or in the ledger.
        </p>
    @endif
    <p>Check the payment in the gateway dashboard and decide what to do.</p>
@endsection
