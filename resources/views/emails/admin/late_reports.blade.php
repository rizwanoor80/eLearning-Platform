@extends('emails.layout')

@section('content')
    <p>{{ $profile->user->name }} ({{ $profile->user->email }}) has had {{ $lateReports }} lesson reports released late within the last 90 days: no report was filed by the deadline, so the platform released the payment.</p>
    <p>Review the tutor's recent lessons before deciding whether any action is needed. Nothing has been changed on the tutor's account.</p>
@endsection
