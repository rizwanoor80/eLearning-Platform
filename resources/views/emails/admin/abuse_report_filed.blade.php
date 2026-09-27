@extends('emails.layout')

@section('content')
    <p>{{ $reporterName }} filed a safeguarding report ({{ $reasonLabel }}) against a {{ str_replace('_', ' ', $subjectTypeLabel) }}.</p>
    <p>Open the Safeguarding queue in the admin panel to review it.</p>
@endsection
