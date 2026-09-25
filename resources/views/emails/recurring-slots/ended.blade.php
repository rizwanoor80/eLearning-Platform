@extends('emails.layout')

@section('content')
    <p>Hi {{ $recipient->name }},</p>
    <p>
        The weekly lesson slot for {{ $slot->learner->display_name }} with {{ $slot->tutorProfile->displayName() }}
        on {{ $slot->subject->name }} ({{ $slot->scheduleLabel() }})
        @php($until = $lastDay ?? $slot->effectiveEndDate()?->toDateString())
        @if ($endedBy === \App\Enums\Role::Tutor && $until !== null)
            will end after {{ \Carbon\CarbonImmutable::parse($until)->format('l, j M Y') }}, following the notice the tutor gave.
        @elseif ($endedBy === \App\Enums\Role::Tutor)
            has been ended, following the notice the tutor gave.
        @elseif ($endedBy === \App\Enums\Role::Admin)
            was ended by the platform team.
        @else
            has been ended from the parent's account.
        @endif
    </p>
    <p>
        @if ($releasedLessons > 0)
            {{ $releasedLessons }} {{ $releasedLessons === 1 ? 'lesson' : 'lessons' }} that had not yet been charged
            {{ $releasedLessons === 1 ? 'was' : 'were' }} released, free of charge.
        @else
            No uncharged lessons were left to release.
        @endif
        @if ($paidLessonsRemaining > 0)
            {{ $paidLessonsRemaining }} {{ $paidLessonsRemaining === 1 ? 'lesson is' : 'lessons are' }} already paid for and
            stay scheduled; they follow the normal cancellation rules.
        @endif
    </p>
@endsection
