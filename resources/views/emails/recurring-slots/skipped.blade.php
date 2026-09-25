@extends('emails.layout')

@section('content')
    <p>Hi {{ $recipient->name }},</p>
    <p>
        {{ $skips->count() === 1 ? 'One lesson' : 'Some lessons' }} in the weekly slot for {{ $slot->learner->display_name }}
        with {{ $slot->tutorProfile->displayName() }} on {{ $slot->subject->name }} could not be scheduled.
        Nothing was reserved and nothing is charged for {{ $skips->count() === 1 ? 'this date' : 'these dates' }}:
    </p>
    <ul>
        @foreach ($skips as $skip)
            <li>
                {{ $skip->starts_at->setTimezone($recipient->timezone)->format('l, j M Y \a\t H:i') }} ({{ $recipient->timezone }})
                &mdash;
                @if ($skip->reason === \App\Enums\RecurringSlotSkipReason::TutorBlocked)
                    the tutor is away that day
                @elseif ($skip->reason === \App\Enums\RecurringSlotSkipReason::LessonCollision)
                    the tutor has another lesson at that time
                @else
                    the tutor is not taking lessons at the moment
                @endif
            </li>
        @endforeach
    </ul>
    <p>Your weekly slot stays in place for the other dates.</p>
@endsection
