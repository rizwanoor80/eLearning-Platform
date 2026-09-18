<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; font-size: 14px; color: #111;">
    @yield('content')

    @php($footer = \App\Support\Facades\Settings::get('email_footer'))
    @if ($footer)
        <p style="margin-top: 2em; color: #666; font-size: 12px;">{{ $footer }}</p>
    @endif
</body>
</html>
