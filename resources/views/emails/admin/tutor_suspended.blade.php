@extends('emails.layout')

@section('content')
    <p>{{ $profile->user->name }} ({{ $profile->user->email }}) has been automatically suspended after accumulating {{ $strikeCount }} strikes within the last 90 days.</p>
    <p>Review the tutor's recent lessons and strikes before deciding whether to reinstate.</p>
@endsection
