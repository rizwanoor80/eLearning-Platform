<?php

namespace App\Support\Messaging;

use Normalizer;
use RuntimeException;

/**
 * R134: the one place a message body is masked. Pure — no database, no clock, no I/O — so its fixture
 * table is the whole specification. Before a pair's first completed lesson (invariant #8) emails, URLs
 * (with or without a scheme, bare domains included) and phone numbers are replaced by
 * `PLACEHOLDER`; the caller stores the result and never the original.
 *
 * Order matters: emails first (so the digits and dots inside an address are consumed with it), then
 * URLs and bare domains, then phone runs. The text is first put through NFKC (fullwidth `＠` and digits,
 * circled and superscript digits fold to their plain forms) and stripped of invisible format
 * characters, and invalid UTF-8 is scrubbed, so none of those can be used to slip a number past the
 * patterns. Digits are matched as `\p{Nd}`, which covers Arabic-Indic and Persian digits — PCRE's `\d`
 * does not, even with `/u`.
 *
 * Known limits, disclosed in ADR-019: numbers and addresses spelled out in words ("zero five zero…",
 * "name at gmail dot com", "(at)"/"(dot)"), digits split by words or replaced by lookalike letters,
 * separators of more than four characters or a colon, and social handles are not caught. The safe side
 * is over-masking: dates (27-09-2026, 27/09/2026), "Year 10, 11, 12, 13", 10,000,000 and a file name such
 * as solution.py are masked too; 16:00-17:00 and ordinary sentence ends are left alone.
 */
class MessageMasker
{
    public const PLACEHOLDER = '[hidden until after your first lesson]';

    private const MIN_PHONE_DIGITS = 7;

    /** Zero-width and direction characters, the soft hyphen, word joiners and the BOM. */
    private const INVISIBLE = '~[\x{00AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]~u';

    private const EMAIL = '~[\p{L}\p{N}._%+\-]+ ?@ ?[\p{L}\p{N}\-]+(?: ?\. ?[\p{L}\p{N}\-]+)+~u';

    private const URL_WITH_SCHEME = '~\b[a-z][a-z0-9+.\-]{1,15}://\S+~iu';

    private const URL_WWW = '~(?<![\p{L}\p{N}])www\.\S+~iu';

    /** A named ending after a dotted label. The dot may carry a space either side, but not only one after it ("it. Online"). */
    private const NAMED_DOMAIN = '~(?<![\p{L}\p{N}])(?:[\p{L}\p{N}\-]+(?:\.| \.| \. ))+(?:com|net|org|edu|gov|mil|int|info|biz|app|dev|xyz|online|site|tech|link|page|club|shop|store|blog|live|news|pro|cloud|academy|wiki|name|mobi)(?![\p{L}\p{N}])(?:[/:?#]\S*)?~iu';

    /** Any two-letter country code after a dotted label; dot-tight only, or "see you. Me too" would be hidden. */
    private const COUNTRY_DOMAIN = '~(?<![\p{L}\p{N}])(?:[\p{L}\p{N}\-]+\.)+[a-z]{2}(?![\p{L}\p{N}])(?:[/:?#]\S*)?~iu';

    /**
     * Digits with up to six separator units between them, a unit being a whole run of whitespace or one character
     * that is neither a letter nor a digit — spaces, tabs, line breaks, dots, dashes, brackets, commas, emoji keycap marks, `*`, `|`, `#`, anything. `:` is left out so
     * a time range such as 16:00-17:00 survives; a number written 050:123:4567 is a disclosed limit.
     */
    private const PHONE_RUN = '~[+(\[]{0,2}\p{Nd}(?:(?:\s++|[^\p{L}\p{Nd}:\s]){0,6}\p{Nd})+\p{M}*~u';

    public function mask(string $body): MaskedMessage
    {
        $text = mb_scrub($body, 'UTF-8');

        // ext-intl is a Filament requirement, so it is always there; a failure refuses the message.
        $text = $this->orFail(Normalizer::normalize($text, Normalizer::FORM_KC));
        $text = $this->replace(self::INVISIBLE, '', $text);

        $hidden = 0;
        $masked = $text;

        foreach ([self::EMAIL, self::URL_WITH_SCHEME, self::URL_WWW, self::NAMED_DOMAIN, self::COUNTRY_DOMAIN] as $pattern) {
            $masked = $this->replace($pattern, self::PLACEHOLDER, $masked, $hidden);
        }

        $masked = $this->replaceCallback(
            self::PHONE_RUN,
            function (array $match) use (&$hidden): string {
                if (preg_match_all('~\p{Nd}~u', $match[0]) < self::MIN_PHONE_DIGITS) {
                    return $match[0];
                }
                $hidden++;

                return self::PLACEHOLDER;
            },
            $masked,
        );

        // "masked" means something was hidden, not that NFKC or the invisible-character strip changed the text.
        return new MaskedMessage($masked, $hidden > 0);
    }

    private function replace(string $pattern, string $replacement, string $subject, int &$count = 0): string
    {
        $done = 0;
        $result = $this->orFail(preg_replace($pattern, $replacement, $subject, -1, $done));
        $count += $done;

        return $result;
    }

    private function replaceCallback(string $pattern, callable $callback, string $subject): string
    {
        return $this->orFail(preg_replace_callback($pattern, $callback, $subject));
    }

    /**
     * A regex that errors (backtrack limit, bad UTF-8) must never let the original text through:
     * the message is refused instead.
     */
    private function orFail(string|false|null $result): string
    {
        if ($result === false || $result === null) {
            throw new RuntimeException('The message could not be masked, so it was not stored.');
        }

        return $result;
    }
}
