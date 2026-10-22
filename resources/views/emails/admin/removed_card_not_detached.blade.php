@extends('emails.layout')

@section('content')
    <p>
        Saved card {{ $paymentMethodId }} was removed by its parent, but the payment gateway would not detach it
        after repeated attempts. The platform will never charge it again; only the gateway still holds it.
    </p>
    <p>Detach it in the gateway dashboard.</p>
@endsection
