@extends('emails.layout')

@section('content')
    <p>Hi {{ $recipient->name }},</p>
    <p>
        You have a new message from {{ $senderName }}.
    </p>
    <p>
        <a href="{{ route('messages.show', $conversation) }}">Open the conversation</a>
    </p>
@endsection
