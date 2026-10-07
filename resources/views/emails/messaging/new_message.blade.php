@extends('emails.layout')

@section('content')
    <p>{{ \App\Support\Mail\MailBrand::greeting($recipient->name) }}</p>
    <p>
        You have a new message from {{ $senderName }}.
    </p>
    <p>
        <a href="{{ route('messages.show', $conversation) }}">Open the conversation</a>
    </p>
@endsection
