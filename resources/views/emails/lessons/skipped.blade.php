@extends('emails.layout')

@section('content')
    <p>Hi {{ $recipient->name }},</p>
    <p>
        The lesson between {{ $lesson->tutorProfile->displayName() }} and {{ $lesson->learner->display_name }}
        on {{ $lesson->subject->name }}, scheduled for
        {{ $lesson->starts_at->setTimezone($recipient->timezone)->format('l, j M Y \a\t H:i') }}
        ({{ $recipient->timezone }}), was skipped free of charge — nothing was paid and nothing is owed.
    </p>
@endsection
