@extends('emails.layout')

@section('content')
    @php($lesson = $report->lesson)
    <p>Hi {{ $recipient->name }},</p>
    <p>
        {{ $lesson->tutorProfile->displayName() }} has written a report on {{ $lesson->learner->display_name }}'s
        {{ $lesson->subject->name }} lesson on
        {{ $lesson->starts_at->setTimezone($recipient->timezone)->format('l, j M Y') }}.
    </p>
    <p><strong>Topics covered</strong><br>{!! nl2br(e($report->topics_covered)) !!}</p>
    <p><strong>What went well</strong><br>{!! nl2br(e($report->went_well)) !!}</p>
    <p><strong>What to work on next</strong><br>{!! nl2br(e($report->work_on_next)) !!}</p>
    <p><strong>Homework set</strong><br>{!! nl2br(e($report->homework)) !!}</p>
    <p><strong>Engagement</strong><br>{{ $report->engagement }} out of 5</p>

    @if ($report->trial_suitability !== null)
        <p><strong>Fit for {{ $lesson->learner->display_name }}</strong><br>{{ $report->trial_suitability->label() }}</p>
        <p><strong>Recommended frequency</strong><br>{{ $report->trial_recommended_frequency }} {{ $report->trial_recommended_frequency === 1 ? 'lesson' : 'lessons' }} a week</p>
        <p><strong>Focus areas for the first month</strong><br>{!! nl2br(e($report->trial_focus_areas)) !!}</p>
        <p>
            <a href="{{ route('weekly-slots.create', ['tutor' => $lesson->tutor_profile_id, 'learner' => $lesson->learner_id]) }}">Set up a weekly slot with {{ $lesson->tutorProfile->displayName() }}</a>
        </p>
    @endif
@endsection
