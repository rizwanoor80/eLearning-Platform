<x-mail::layout>
{{-- Header: the TrusTutor lockup (R174b); the slot is ignored on purpose --}}
<x-slot:header>
<x-mail::header :url="\App\Support\Mail\MailBrand::homeUrl()">
TrusTutor
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer: the `mail` settings group (R174b) --}}
<x-slot:footer>
<x-mail::footer>
@if (\App\Support\Mail\MailBrand::footerText())
{{ \App\Support\Mail\MailBrand::footerText() }}

@endif
@if (\App\Support\Mail\MailBrand::supportAddress())
Questions? Write to {{ \App\Support\Mail\MailBrand::supportAddress() }}.

@endif
{{ \App\Support\Mail\MailBrand::senderName() }} · {{ \App\Support\Mail\MailBrand::TAGLINE }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
