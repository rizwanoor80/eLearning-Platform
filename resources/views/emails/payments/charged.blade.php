@extends('emails.layout')

@section('content')
    <p>Hi {{ $recipient->name }},</p>
    <p>
        Your weekly lesson for {{ $lesson->learner->display_name }} with {{ $lesson->tutorProfile->displayName() }}
        on {{ $lesson->subject->name }} is paid and confirmed for
        {{ $lesson->starts_at->setTimezone($recipient->timezone)->format('l, j M Y \a\t H:i') }}
        ({{ $recipient->timezone }}). The charge was made to your saved card.
    </p>
@endsection
