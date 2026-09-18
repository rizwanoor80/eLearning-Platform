@extends('emails.layout')

@section('content')
    @if ($daysRemaining === null)
        <p>The tutoring permit for {{ $profile->user->name }} ({{ $profile->user->email }}) expired on {{ $profile->permit_expires_at->toFormattedDateString() }}. They are no longer bookable.</p>
    @else
        <p>The tutoring permit for {{ $profile->user->name }} ({{ $profile->user->email }}) expires on {{ $profile->permit_expires_at->toFormattedDateString() }} ({{ $daysRemaining }} days from now).</p>
    @endif
@endsection
