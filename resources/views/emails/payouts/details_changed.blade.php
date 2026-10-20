@extends('emails.layout')

@section('content')
    <p>{{ \App\Support\Mail\MailBrand::greeting($profile->user->name) }}</p>
    <p>Your payout details were just saved. Your payout method is now <strong>{{ $methodLabel }}</strong>.</p>
    <p>If this was you, there is nothing more to do. If it was not, change your password from Settings and contact us straight away, before your next payout.</p>
@endsection
