@extends('emails.layout')

@section('content')
    <p>Hi {{ $recipient->name }},</p>
    <p>
        Your {{ $lesson->subject->name }} lesson with {{ $lesson->learner->display_name }} is over. Please write its
        report{{ $lesson->report_due_at ? ' by '.$lesson->report_due_at->setTimezone($recipient->timezone)->format('l, j M Y \a\t H:i').' ('.$recipient->timezone.')' : '' }}.
        Your payment for the lesson is released when you submit it.
    </p>
    <p><a href="{{ route('lessons.report.create', $lesson) }}">Write the report</a></p>
@endsection
