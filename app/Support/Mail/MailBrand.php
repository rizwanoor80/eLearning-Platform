<?php

namespace App\Support\Mail;

use App\Support\Facades\Settings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/**
 * R174: the pieces every outgoing email shares — the sender name, the logo lockup, the first-name greeting and
 * the settings-driven footer — so the mailable layout (`emails.layout`) and the vendored notification theme
 * (`vendor/mail`) cannot drift apart. Everything is read at call time, never cached: a queue worker boots once,
 * and an admin's next settings edit has to reach the next email.
 */
final class MailBrand
{
    public const TAGLINE = 'Tutoring, Electrified.';

    /** docs/brand "primary" and "accent" (R49), as inline hex — email clients do not resolve CSS variables. */
    public const MAROON = '#64011D';

    public const CORAL = '#FD4C53';

    /** The `from_name` setting; when it is empty, the configured default (`TrusTutor`) — never the site name. */
    public static function senderName(): string
    {
        $name = Settings::get('from_name');

        return is_string($name) && trim($name) !== '' ? trim($name) : (string) config('settings.defaults.from_name');
    }

    /**
     * Absolute, because a queued mail renders outside any request: no request root to resolve a relative
     * asset against. The 600px wordmark, shown no wider than its pixel width (docs/brand README §1: never upscale).
     */
    public static function wordmarkUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/brand/trustutor-wordmark-colour-600.png';
    }

    public static function homeUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/';
    }

    /** First whitespace-delimited word of a display name; null when there is none to greet. */
    public static function firstName(?string $name): ?string
    {
        $word = preg_split('/\s+/', trim((string) $name), 2)[0] ?? '';

        return $word === '' ? null : $word;
    }

    /** "Hi Amira," — "Hi there," when the recipient has no usable name. */
    public static function greeting(?string $name): string
    {
        return 'Hi '.(self::firstName($name) ?? 'there').',';
    }

    /**
     * Gives a notification's MailMessage the TrusTutor greeting and sign-off instead of Laravel's Hello! and
     * Regards, <app name>; the surrounding template is the vendored `vendor/mail` theme. The notification
     * theme renders the greeting through Markdown, so the user-chosen name is Markdown-escaped there.
     */
    public static function brandNotification(MailMessage $message, object $notifiable): MailMessage
    {
        $name = data_get($notifiable, 'name');

        return $message
            ->greeting(self::escapeMarkdown(self::greeting(is_string($name) ? $name : null)))
            ->salutation(new HtmlString('Regards,<br>'.e(self::senderName())));
    }

    /** Backslash-escapes the Markdown-significant punctuation (CommonMark treats an escaped ASCII punctuation mark as literal). */
    private static function escapeMarkdown(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]()<>#!~|])/', '\\\\$1', $text);
    }

    public static function footerText(): ?string
    {
        $footer = Settings::get('email_footer');

        return is_string($footer) && trim($footer) !== '' ? trim($footer) : null;
    }

    public static function supportAddress(): ?string
    {
        $address = Settings::get('support_address');

        return is_string($address) && trim($address) !== '' ? trim($address) : null;
    }
}
