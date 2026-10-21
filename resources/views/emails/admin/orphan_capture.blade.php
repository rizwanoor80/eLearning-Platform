@extends('emails.layout')

@section('content')
    <p>
        {{ $gateway }} reports that {{ $formatted }} was taken on payment reference {{ $intentId }}, but the platform has no
        payment for it. Nothing has been changed and nothing has been refunded.
    </p>
    <p>Look the reference up in the gateway dashboard, and refund it there if no booking should exist for it.</p>
@endsection
