@extends('emails.layout')

@section('content')
    <p>{{ \App\Support\Mail\MailBrand::greeting($profile->user->name) }}</p>
    <p>Great news — your profile has been approved. You're now bookable for lessons.</p>
@endsection
