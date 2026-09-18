@extends('emails.layout')

@section('content')
    <p>Hi {{ $profile->user->name }},</p>
    <p>We've reviewed your profile and need a few changes before we can approve it:</p>
    <blockquote style="border-left: 3px solid #ccc; margin: 1em 0; padding-left: 1em; color: #333;">
        {{ $profile->review_note }}
    </blockquote>
    <p>Please sign in and update your profile, then submit it again for review.</p>
@endsection
