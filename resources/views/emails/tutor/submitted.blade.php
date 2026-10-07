@extends('emails.layout')

@section('content')
    <p>{{ \App\Support\Mail\MailBrand::greeting($profile->user->name) }}</p>
    <p>Thanks for completing your onboarding. Your profile is now with our team for review — we'll email you as soon as there's a decision.</p>
@endsection
