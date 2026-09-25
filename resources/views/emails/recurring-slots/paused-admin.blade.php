@extends('emails.layout')

@section('content')
    <p>Hello,</p>
    <p>
        The weekly lesson slot for {{ $slot->learner->display_name }} with {{ $slot->tutorProfile->displayName() }}
        on {{ $slot->subject->name }} ({{ $slot->scheduleLabel() }}) was paused automatically after
        {{ $slot->consecutive_charge_failures }} consecutive failed charges.
        @if ($releasedLessons > 0)
            {{ $releasedLessons }} upcoming {{ $releasedLessons === 1 ? 'lesson' : 'lessons' }} that had not yet been charged
            {{ $releasedLessons === 1 ? 'was' : 'were' }} released, free of charge.
        @endif
        The parent can resume it after replacing the saved card; an admin can also resume it.
    </p>
@endsection
