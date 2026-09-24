@extends('emails.layout')

@section('content')
    <p>Hi {{ $recipient->name }},</p>
    <p>
        The lesson between {{ $lesson->tutorProfile->displayName() }} and {{ $lesson->learner->display_name }}
        on {{ $lesson->subject->name }}, scheduled for
        {{ $lesson->starts_at->setTimezone($recipient->timezone)->format('l, j M Y \a\t H:i') }}
        ({{ $recipient->timezone }}), has been cancelled.
    </p>
    @if ($lesson->cancel_reason)
        <p>Reason given: {{ $lesson->cancel_reason }}</p>
    @endif
@endsection
