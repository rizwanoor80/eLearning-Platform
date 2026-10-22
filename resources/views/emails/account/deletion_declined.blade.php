@extends('emails.layout')

@section('content')
    <p>{{ \App\Support\Mail\MailBrand::greeting($request->user->name) }}</p>
    <p>We are not able to delete your account yet. Here is why:</p>
    <blockquote style="border-left: 3px solid #ccc; margin: 1em 0; padding-left: 1em; color: #333;">
        {{ $request->admin_note }}
    </blockquote>
    <p>Your account is unchanged. Once that is sorted out you can ask again from Settings, in your profile.</p>
@endsection
