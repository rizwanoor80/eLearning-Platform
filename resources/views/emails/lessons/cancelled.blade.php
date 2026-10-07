@extends('emails.layout')

@section('content')
    <p>{{ \App\Support\Mail\MailBrand::greeting($recipient->name) }}</p>
    <p>
        The lesson between {{ $lesson->tutorProfile->displayName() }} and {{ $lesson->learner->display_name }}
        on {{ $lesson->subject->name }}, scheduled for
        {{ $lesson->starts_at->setTimezone($recipient->timezone)->format('l, j M Y \a\t H:i') }}
        ({{ $recipient->timezone }}), has been cancelled.
    </p>
    {{-- A machine value (LessonCancelReason) is never customer-facing copy — skip the line for one,
         same guard SendLessonSkippedMail already applies to its own send. A genuine tutor/parent-typed
         reason still shows as it always has. --}}
    @if ($lesson->cancel_reason && ! \App\Enums\LessonCancelReason::isReserved($lesson->cancel_reason))
        <p>Reason given: {{ $lesson->cancel_reason }}</p>
    @endif
@endsection
