@extends('emails.layout')

@section('content')
    <p>Hi {{ $profile->user->name }},</p>
    <p>Your tutoring permit expires on {{ $profile->permit_expires_at->toFormattedDateString() }} ({{ $daysRemaining }} days from now). Please renew it and update your permit details before it expires, or you'll no longer be bookable for lessons.</p>
@endsection
