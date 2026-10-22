@extends('emails.layout')

@section('content')
    <p>{{ \App\Support\Mail\MailBrand::greeting($request->user->name) }}</p>
    <p>We received your request to delete your account. Nothing has been deleted yet: a member of our team reviews every request.</p>
    <p>We cannot delete an account while a lesson is still open, and we may ask you to wait if money is still owed on it. If we cannot go ahead, we will write to you with what needs to happen first; otherwise your account is erased and we will not email you again. You do not need to do anything else, and you can keep using your account until then.</p>
@endsection
