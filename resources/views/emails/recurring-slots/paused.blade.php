@extends('emails.layout')

@section('content')
    <p>Hi {{ $recipient->name }},</p>
    <p>
        The weekly lesson slot for {{ $slot->learner->display_name }} with {{ $slot->tutorProfile->displayName() }}
        on {{ $slot->subject->name }} ({{ $slot->scheduleLabel() }}) has been paused.
        No new lessons will be scheduled until it is resumed; the slot keeps its day and time.
    </p>
    <p>
        @if ($releasedLessons > 0)
            {{ $releasedLessons }} upcoming {{ $releasedLessons === 1 ? 'lesson' : 'lessons' }} that had not yet been charged
            {{ $releasedLessons === 1 ? 'was' : 'were' }} released, free of charge.
        @else
            No uncharged lessons were left to release.
        @endif
    </p>
@endsection
