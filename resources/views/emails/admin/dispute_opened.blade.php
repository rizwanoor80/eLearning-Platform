@extends('emails.layout')

@section('content')
    <p>
        A dispute ({{ $dispute->reason->label() }}) was opened on a lesson between
        {{ $dispute->lesson->tutorProfile->displayName() }} and {{ $dispute->lesson->learner->display_name }}.
    </p>
    <p>Open the Disputes queue in the admin panel to review it.</p>
@endsection
