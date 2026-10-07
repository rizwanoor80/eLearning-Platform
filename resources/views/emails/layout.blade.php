@php
    use App\Support\Mail\MailBrand;

    $footer = MailBrand::footerText();
    $support = MailBrand::supportAddress();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ MailBrand::senderName() }}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #FBF8F7; }
        .tt-body p, .tt-body li { margin: 0 0 14px; font-size: 15px; line-height: 1.55; color: #4A3A3E; }
        .tt-body h1, .tt-body h2 { margin: 0 0 14px; color: #241016; }
        .tt-body a { color: #8A0A2A; }
        @media only screen and (max-width: 620px) {
            .tt-card { width: 100% !important; }
            .tt-tagline { display: none !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #FBF8F7; font-family: Helvetica, Arial, sans-serif; color: #4A3A3E;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #FBF8F7;">
        <tr>
            <td align="center" style="padding: 24px 12px;">
                <table role="presentation" class="tt-card" width="600" cellpadding="0" cellspacing="0" style="width: 600px; max-width: 100%; background-color: #FFFFFF; border: 1px solid #E7DEDE; border-radius: 16px;">
                    <tr>
                        <td style="height: 4px; line-height: 4px; font-size: 4px; background-color: {{ MailBrand::MAROON }}; border-radius: 16px 16px 0 0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td data-brand="lockup" style="padding: 24px 32px 16px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="vertical-align: middle;">
                                        <a href="{{ MailBrand::homeUrl() }}" style="text-decoration: none;"><img src="{{ MailBrand::wordmarkUrl() }}" alt="TrusTutor" width="150" style="display: block; width: 150px; max-width: 150px; height: auto; border: 0;"></a>
                                    </td>
                                    <td class="tt-tagline" style="vertical-align: middle; padding: 0 0 0 14px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="border-inline-start: 1px solid #E7DEDE; padding: 2px 0 2px 14px; font-size: 13px; color: #5A5257;">{{ MailBrand::TAGLINE }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="tt-body" style="padding: 8px 32px 24px; font-size: 15px; line-height: 1.55; color: #4A3A3E;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 32px 24px; border-top: 1px solid #F4EBEB; font-size: 12px; line-height: 1.5; color: #5A5257;">
                            @if ($footer)
                                <p style="margin: 0 0 8px;">{{ $footer }}</p>
                            @endif
                            @if ($support)
                                <p style="margin: 0 0 8px;">Questions? Write to <a href="mailto:{{ $support }}" style="color: #8A0A2A;">{{ $support }}</a>.</p>
                            @endif
                            <p style="margin: 0;">{{ MailBrand::senderName() }} &middot; {{ MailBrand::TAGLINE }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
