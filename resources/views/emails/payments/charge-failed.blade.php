@extends('emails.layout')

@section('content')
    <p>{{ \App\Support\Mail\MailBrand::greeting($recipient->name) }}</p>
    <p>
        We could not charge your saved card for the weekly lesson for {{ $lesson->learner->display_name }}
        with {{ $lesson->tutorProfile->displayName() }} on {{ $lesson->subject->name }}, scheduled for
        {{ $lesson->starts_at->setTimezone($recipient->timezone)->format('l, j M Y \a\t H:i') }}
        ({{ $recipient->timezone }}).
    </p>
    <p>
        We will try again on
        {{ $retryAt->setTimezone($recipient->timezone)->format('l, j M Y \a\t H:i') }} ({{ $recipient->timezone }}).
        To make sure it goes through, please check your saved card or replace it before then.
    </p>
    @if ($cardNeedsReplacing)
        <p>
            We changed how payments are processed, so your saved card has to be added again. Please open your
            card page and add it once more before then; until you do, weekly lessons cannot be charged.
        </p>
    @endif
@endsection
