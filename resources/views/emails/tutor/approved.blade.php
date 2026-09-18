@extends('emails.layout')

@section('content')
    <p>Hi {{ $profile->user->name }},</p>
    <p>Great news — your profile has been approved. You're now bookable for lessons.</p>
@endsection
