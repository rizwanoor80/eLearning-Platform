@extends('emails.layout')

@section('content')
    <p>Hi {{ $recipient->name }},</p>
    <p>
        A reminder: your lesson between {{ $lesson->tutorProfile->displayName() }} and {{ $lesson->learner->display_name }}
        on {{ $lesson->subject->name }} starts
        {{ $window === '24h' ? 'tomorrow' : 'in about an hour' }},
        at {{ $lesson->starts_at->setTimezone($recipient->timezone)->format('l, j M Y \a\t H:i') }}
        ({{ $recipient->timezone }}).
    </p>
@endsection
