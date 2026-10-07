@extends('emails.layout')

@section('content')
    <p>{{ \App\Support\Mail\MailBrand::greeting($dispute->lesson->tutorProfile->user->name) }}</p>
    <p>
        A dispute has been opened on your lesson with {{ $dispute->lesson->learner->display_name }}
        on {{ $dispute->lesson->subject->name }}, scheduled for
        {{ $dispute->lesson->starts_at->setTimezone($dispute->lesson->tutorProfile->user->timezone)->format('l, j M Y \a\t H:i') }}
        ({{ $dispute->lesson->tutorProfile->user->timezone }}).
    </p>
    <p>Reason given: {{ $dispute->reason->label() }}</p>
    <p>The lesson is on hold in your Earnings while it is reviewed. We'll let you know once it's resolved.</p>
@endsection
