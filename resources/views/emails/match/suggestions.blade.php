@extends('emails.layout')

@section('content')
    <p>Hi {{ $matchRequest->account->name }},</p>
    <p>Thanks for your request. Here {{ count($cards) === 1 ? 'is a tutor' : 'are '.count($cards).' tutors' }} we think could be a good fit:</p>
    @foreach ($cards as $card)
        <p>
            <strong>{{ $card['name'] }}</strong>@if ($card['headline']) — {{ $card['headline'] }}@endif<br>
            {{ $card['rate'] }} / hour @if ($card['trial_price']) · trial lesson {{ $card['trial_price'] }}@endif<br>
            <a href="{{ route('tutors.show', $card['id']) }}">View profile and book a trial</a>
        </p>
    @endforeach
@endsection
