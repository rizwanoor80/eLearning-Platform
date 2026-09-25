@extends('emails.layout')

@section('content')
    <p>Hi {{ $recipient->name }},</p>
    @if ($isParent)
        <p>
            The weekly lesson with {{ $lesson->tutorProfile->displayName() }} for {{ $lesson->learner->display_name }}
            on {{ $lesson->subject->name }}, scheduled for
            {{ $lesson->starts_at->setTimezone($recipient->timezone)->format('l, j M Y \a\t H:i') }}
            ({{ $recipient->timezone }}), has been cancelled because the tutor is not available for it.
            @if ($nothingCharged)
                Nothing was charged.
            @endif
        </p>
        <p>
            Your weekly slot is still active. You can keep it while the tutor's availability is sorted out,
            or end it from your dashboard at any time.
        </p>
    @else
        <p>
            Your weekly lesson with {{ $lesson->learner->display_name }} on {{ $lesson->subject->name }}, scheduled for
            {{ $lesson->starts_at->setTimezone($recipient->timezone)->format('l, j M Y \a\t H:i') }}
            ({{ $recipient->timezone }}), has been cancelled because your tutor profile is not currently bookable.
            @if ($nothingCharged)
                The family was not charged.
            @endif
            This does not count as a strike.
        </p>
    @endif
@endsection
