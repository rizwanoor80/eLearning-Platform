<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="\App\Support\Mail\MailBrand::homeUrl()">
            TrusTutor
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            @if (\App\Support\Mail\MailBrand::footerText())
                {{ \App\Support\Mail\MailBrand::footerText() }}
            @endif
            {{ \App\Support\Mail\MailBrand::senderName() }} · {{ \App\Support\Mail\MailBrand::TAGLINE }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
