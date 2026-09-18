@extends('emails.layout')

@section('content')
    <p>Hi {{ $profile->user->name }},</p>
    <p>After review, we're not able to approve your profile at this time.</p>
    @if ($profile->review_note)
        <blockquote style="border-left: 3px solid #ccc; margin: 1em 0; padding-left: 1em; color: #333;">
            {{ $profile->review_note }}
        </blockquote>
    @endif
@endsection
