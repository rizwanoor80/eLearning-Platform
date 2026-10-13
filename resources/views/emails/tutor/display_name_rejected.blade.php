@extends('emails.layout')

@section('content')
    <p>{{ \App\Support\Mail\MailBrand::greeting($profile->user->name) }}</p>
    <p>We're not able to use the display name you asked for: <strong>{{ $name }}</strong>.</p>
    <blockquote style="border-left: 3px solid #ccc; margin: 1em 0; padding-left: 1em; color: #333;">
        {{ $note }}
    </blockquote>
    <p>Parents keep seeing <strong>{{ $profile->displayName() }}</strong>. You can ask for a different display name from Settings, in your profile.</p>
@endsection
