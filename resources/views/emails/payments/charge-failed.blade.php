@extends('emails.layout')

@section('content')
    <p>Hi {{ $recipient->name }},</p>
    <p>
        We could not charge your saved card for the weekly lesson for {{ $lesson->learner->display_name }}
        with {{ $lesson->tutorProfile->displayName() }} on {{ $lesson->subject->name }}, scheduled for
        {{ $lesson->starts_at->setTimezone($recipient->timezone)->format('l, j M Y \a\t H:i') }}
        ({{ $recipient->timezone }}).
    </p>
    <p>
        We will try again on
        {{ $retryAt->setTimezone($recipient->timezone)->format('l, j M Y \a\t H:i') }} ({{ $recipient->timezone }}).
        To make sure it goes through, please check your saved card or replace it before then.
    </p>
@endsection
