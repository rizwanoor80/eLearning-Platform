@extends('emails.layout')

@section('content')
    <p>Hi {{ $profile->user->name }},</p>
    <p>Your tutoring permit expired on {{ $profile->permit_expires_at->toFormattedDateString() }}. You're no longer bookable for lessons until you update your permit details.</p>
@endsection
