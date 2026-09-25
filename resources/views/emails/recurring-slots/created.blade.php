@extends('emails.layout')

@section('content')
    @php($next = $slot->nextOccurrenceAfter(now()))
    <p>Hi {{ $recipient->name }},</p>
    <p>
        A weekly lesson slot was set up for {{ $slot->learner->display_name }} with {{ $slot->tutorProfile->displayName() }}
        on {{ $slot->subject->name }}: every {{ $slot->scheduleLabel() }}, starting from {{ $slot->starts_on->format('j M Y') }}{{ $slot->ends_on ? ' until '.$slot->ends_on->format('j M Y') : '' }}.
    </p>
    @if ($next)
        <p>
            The first lesson is on {{ $next->setTimezone($recipient->timezone)->format('l, j M Y \a\t H:i') }} ({{ $recipient->timezone }}).
        </p>
    @endif
    <p>
        Each lesson is scheduled a few weeks ahead and charged individually before it starts. A weekly slot is not a
        subscription: it can be ended at any time.
    </p>
@endsection
