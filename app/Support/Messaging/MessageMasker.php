<?php

namespace App\Support\Messaging;

use IntlChar;
use Normalizer;
use RuntimeException;

/**
 * R134: the one place a message body is masked. Pure — no database, no clock, no I/O — so its fixture
 * table is the whole specification. Before a pair's first completed lesson (invariant #8) emails, URLs
 * (with or without a scheme, bare domains included) and phone numbers are replaced by
 * `PLACEHOLDER`; the caller stores the result and never the original.
 *
 * R143 (cycle 08 r2): rewritten from a pattern-by-pattern list of separator lookalikes (which round 3
 * of the 8b review kept finding gaps in) to a "skeleton" approach — advisor-consulted design, logged in
 * CYCLE-LOG.md, deviations from R143's literal wording disclosed there and in ADR-019:
 *
 *  - The text is first put through NFKC, invisible-format-character stripping (unchanged from the
 *    previous version, except the zero-width joiner is now spared — see below), and then a digit fold
 *    (`foldEnclosedDigits`) that turns single-digit dingbat/double-circled symbols such as "❶"/"➊"/"⓵"
 *    into plain "1" using `IntlChar::getNumericValue()` rather than an enumerated table, so it is not
 *    limited to the handful of code points a reviewer happened to try. Multi-digit forms ("➉" = ten) and
 *    non-integer numeric symbols ("½", Roman numerals) are left alone — a disclosed limit.
 *  - Email/URL/domain detection no longer enumerates "a dot, or one of these lookalikes, or up to five
 *    spaces": it matches on a run of letters/digits, a *general* gap of "whatever is not a letter or a
 *    digit", and another run of letters/digits — so no specific separator character needs to be listed
 *    for the pattern to see through it. Two gap shapes exist:
 *      - EMAIL/MAIL_PROVIDER: once an `@` is present the gap on either side of it, and between domain
 *        labels, may be any non-alnum junk (spaces, brackets, quotes, doubled/bracketed dots) with a
 *        bounded length — the `@` itself is the strong signal, exactly as before, just generalised.
 *        A run of `@` characters collapses to one, so a doubled `@@` (a plain typo or an attempt to
 *        break the local-part/domain boundary) does not defeat the match.
 *      - Bare domains (no `@`): a *loose* gap (whitespace allowed around the non-alnum separator) is
 *        only accepted before a non-word ending (com, net, org, edu, gov, mil, int, info, biz, app, dev,
 *        xyz, pro, mobi, link, page) — endings that are not ordinary English words. A *tight* gap (no
 *        whitespace at all) is required before a word-like ending (online, school, shop, team, chat…)
 *        and before any two-letter country code, exactly as the previous version already required for
 *        country codes. Without this split, "loose" bare-domain matching plus a fully flattened skeleton
 *        would mask ordinary sentences that happen to end in a dictionary word already on the list
 *        ("book shop", "join our team", "have a chat") — the advisor flagged this and it is the reason
 *        the skeleton is not a single fully-flattened string for the whole message. It also resolves
 *        the fixture conflict between "visit mysite . com now" (must mask — R143/round 3) and "Ok.
 *        Online lessons are fine." (must not mask — pre-existing pin): "com" is non-word so the loose,
 *        spaced dot before it matches; "Online" is word-like so it needs a tight dot, and ". " is not
 *        tight.
 *  - Phone-number separators: still "digits, then up to six separator units, then digits", but a unit
 *    is now one Unicode extended grapheme cluster (`\X`) instead of one code point, so a family emoji or
 *    a flag (several code points, one grapheme) spends one unit of the budget instead of several. This
 *    is why the zero-width joiner is no longer stripped before this stage — stripping it first would
 *    pull a ZWJ-sequence emoji apart into several ungrouped code points, each spending its own unit, and
 *    silently re-introduce the round-3 finding. It is otherwise inert (invisible, and excluded from the
 *    separator/letter distinction below either way) so leaving it in the stored text is harmless. A
 *    unit is disqualified — does not count as separator at all, so the run stops — if it is `:` (a time
 *    range such as 16:00-17:00 must survive) or an ordinary letter (`\p{L}` that is not a modifier
 *    letter `\p{Lm}`; modifier letters such as the Arabic tatweel or the katakana prolonged sound mark
 *    are still accepted as separators, as before).
 *
 * Order matters: emails first (so the digits and dots inside an address are consumed with it), then
 * URLs and bare domains, then phone runs. Invalid UTF-8 is scrubbed first, so it cannot be used to slip
 * a number past the patterns. Digits are matched as `\p{Nd}`, which covers Arabic-Indic and Persian
 * digits — PCRE's `\d` does not, even with `/u`.
 *
 * Known limits, disclosed in ADR-019: numbers and addresses spelled out in words with plain spaces and
 * no punctuation at all ("zero five zero…", "sara at gmail dot com"), digits split by whole words or
 * replaced by lookalike letters, more than six separator *graphemes* between digits (so seven dashes, or
 * four family emoji, get through), a `:` between digits, an address padded with more than five spaces, an
 * address whose provider is not on the short MAIL_PROVIDER list and whose domain has no recognised
 * ending, a bare word-like ending reached only by whitespace and no punctuation at all is deliberately NOT
 * caught (see above), a multi-digit or fractional enclosed-number symbol ("➉", "½", Roman numerals), and
 * social handles are not caught. The safe side is over-masking: dates (27-09-2026, 27/09/2026), "Year 10,
 * 11, 12, 13", 10,000,000, "@ school, then", a file name such as solution.py, a bare domain whose ending
 * is not on the NAMED_DOMAIN list (".art") once any junk punctuation separates its labels, and "(at)"/
 * "(dot)" written with any punctuation around them ("sara (at) gmail (dot) com") are masked too — a label
 * is not distinguished from a spelled-out separator word, deliberately, or a real bare domain built the
 * same way ("mysite.dot.com") would leak instead; the variation selectors and every format character (bar
 * the ZWJ, see above) are stripped from the stored text; 16:00-17:00 and ordinary sentence ends are left
 * alone.
 */
class MessageMasker
{
    public const PLACEHOLDER = '[hidden until after your first lesson]';

    private const MIN_PHONE_DIGITS = 7;

    private const MAX_PHONE_GAP_GRAPHEMES = 6;

    /**
     * Every format character (\p{Cf}: zero-width, direction marks, word joiners, the soft hyphen, the BOM, tag
     * characters) EXCEPT the zero-width joiner U+200D, the invisible Hangul and Khmer fillers, blank Braille and
     * the variation selectors. The ZWJ is spared so an emoji ZWJ-sequence still counts as one grapheme cluster
     * when a phone-number gap is measured (R143 — see the class docblock); it is otherwise invisible and inert.
     */
    private const INVISIBLE = '~(?:(?!\x{200D})\p{Cf})|\x{034F}|\x{115F}|\x{1160}|\x{17B4}|\x{17B5}|\x{180E}|\x{2800}|\x{3164}|[\x{FE00}-\x{FE0F}]|\x{FFA0}|[\x{E0100}-\x{E01EF}]~u';

    /**
     * Any single symbol that is not a letter, digit, whitespace or `@` — one Unicode code point, generalised so no
     * specific lookalike needs listing. `%` is excluded too: percent-encoding ("sara%40gmail.com") must stay a
     * single local-part token, not a junk separator, or the label chain bridges "sara" and "40gmail" into a bogus
     * multi-label bare domain and over-masks the "sara%" prefix that should be left alone.
     */
    private const JUNK = '[^\p{L}\p{Nd}\s@%]';

    /**
     * Whitespace and/or junk freely mixed, possibly empty — used only immediately touching an `@`, where the `@`
     * itself is already the strong signal, so unlike a bare domain no particular punctuation is required: pure
     * spaces ("sara  @  gmail"), pure junk ("sara"@gmail, sara!@gmail) or a mix are all accepted.
     */
    private const AT_GAP = '[^\p{L}\p{Nd}@]{0,8}';

    /** Junk with optional bounding whitespace either side, but never JUST whitespace — used between domain/local-part labels once `@` has already anchored the match. */
    private const LABEL_GAP = '\s{0,5}'.self::JUNK.'{1,6}\s{0,5}';

    /** No whitespace at all — used for bare-domain word-like endings and every two-letter country code. */
    private const TIGHT_JUNK = self::JUNK.'{1,6}';

    /** A "dot, possibly padded with up to five spaces" gap — used for bare-domain non-word endings only. */
    private const LOOSE_DOT_JUNK = '\s{0,5}'.self::JUNK.'{1,3}\s{0,5}';

    private const NON_WORD_ENDINGS = 'com|net|org|edu|gov|mil|int|info|biz|app|dev|xyz|pro|mobi|link|page';

    private const WORD_ENDINGS = 'online|site|tech|club|shop|store|blog|live|news|cloud|academy|wiki|name|email|school|chat|space|world|education|ninja|network|agency|digital|media|life|today|tutor|tutors|zone|team|group';

    /**
     * Local part, an `@` (a run of one or more collapses to one, so "sara@@gmail.com" still matches), loose junk
     * either side of the `@`, then two or more letter/digit labels joined by loose junk. The `@` itself is the
     * strong signal, so unlike a bare domain any junk (not just a dot) is accepted between labels here.
     */
    private const EMAIL = '~(?<![\p{L}\p{Nd}._%+\-])[\p{L}\p{Nd}._%+\-]++'.self::AT_GAP.'@+'.self::AT_GAP.'[\p{L}\p{Nd}\-]+(?:'.self::LABEL_GAP.'[\p{L}\p{Nd}\-]+)+~u';

    /** A well-known mail provider after an `@` needs no domain ending at all: "sara@gmail com" and "sara@gmail" are addresses. */
    private const MAIL_PROVIDER = '~(?<![\p{L}\p{Nd}._%+\-])[\p{L}\p{Nd}._%+\-]++'.self::AT_GAP.'@+'.self::AT_GAP.'(?:gmail|googlemail|hotmail|outlook|yahoo|icloud|proton(?:mail)?|aol|msn)(?![\p{L}\p{Nd}])~iu';

    private const URL_WITH_SCHEME = '~\b[a-z][a-z0-9+.\-]{1,15}://\S+~iu';

    private const URL_WWW = '~(?<![\p{L}\p{Nd}])www\.\S+~iu';

    /** A non-word ending after a loose, dot-shaped gap: "mysite . com", "mysite[.]com", "mysite,,com" all mask. */
    private const NAMED_DOMAIN_LOOSE = '~(?<![\p{L}\p{Nd}])[\p{L}\p{Nd}\-]+(?:'.self::LOOSE_DOT_JUNK.'[\p{L}\p{Nd}\-]+)*'.self::LOOSE_DOT_JUNK.'(?:'.self::NON_WORD_ENDINGS.')(?![\p{L}\p{Nd}])(?:[/:?#]\S*)?~iu';

    /** A word-like ending needs a tight gap — no whitespace at all — or "Ok. Online lessons are fine." would mask. */
    private const NAMED_DOMAIN_TIGHT = '~(?<![\p{L}\p{Nd}])[\p{L}\p{Nd}\-]+(?:'.self::TIGHT_JUNK.'[\p{L}\p{Nd}\-]+)*'.self::TIGHT_JUNK.'(?:'.self::WORD_ENDINGS.')(?![\p{L}\p{Nd}])(?:[/:?#]\S*)?~iu';

    /** Any two-letter country code after a tight-junk label; tight only, or "see you. Me too" would be hidden. */
    private const COUNTRY_DOMAIN = '~(?<![\p{L}\p{Nd}])(?:[\p{L}\p{Nd}\-]+'.self::TIGHT_JUNK.')+[a-z]{2}(?![\p{L}\p{Nd}])(?:[/:?#]\S*)?~iu';

    /**
     * Digits with up to six separator *graphemes* between them (`\X`, not a code point — see the class docblock),
     * a grapheme disqualified from counting as a separator (and so ending the run) if it is `:` or an ordinary
     * letter (a modifier letter such as the Arabic tatweel is still accepted).
     */
    private const PHONE_RUN = '~[+(\[]{0,2}\p{Nd}(?:(?:(?!:)(?!(?=\p{L})(?!\p{Lm}))\X){0,'.self::MAX_PHONE_GAP_GRAPHEMES.'}\p{Nd})+\p{M}*~u';

    public function mask(string $body): MaskedMessage
    {
        $text = mb_scrub($body, 'UTF-8');

        // ext-intl is a Filament requirement, so it is always there; a failure refuses the message.
        $text = $this->orFail(Normalizer::normalize($text, Normalizer::FORM_KC));
        $text = $this->replace(self::INVISIBLE, '', $text);
        $text = $this->foldEnclosedDigits($text);

        $hidden = 0;
        $masked = $text;

        foreach ([
            self::EMAIL,
            self::URL_WITH_SCHEME,
            self::URL_WWW,
            self::NAMED_DOMAIN_LOOSE,
            self::NAMED_DOMAIN_TIGHT,
            self::COUNTRY_DOMAIN,
            // MAIL_PROVIDER runs last: run before the bare-domain patterns, a partial match (e.g.
            // "sara@gmail" alone) leaves real trailing text ("  com") directly against the freshly
            // inserted PLACEHOLDER's own "]", which NAMED_DOMAIN_LOOSE then spuriously bridges into a
            // second, nested placeholder. Running it last means EMAIL/the bare-domain patterns already
            // consumed every case MAIL_PROVIDER would otherwise partially match.
            self::MAIL_PROVIDER,
        ] as $pattern) {
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

        // "masked" means something was hidden, not that NFKC, digit-folding or the invisible-character strip changed the text.
        return new MaskedMessage($masked, $hidden > 0);
    }

    /**
     * Folds single-digit enclosed/dingbat number symbols ("❶", "➊", "⓵") to a plain ASCII digit using the
     * Unicode Numeric_Value property, so no specific code point needs to be enumerated (R143 — advisor-suggested
     * over a hand-built table). Only symbols whose numeric value is a whole number 0-9 are folded — multi-digit
     * forms ("➉" = ten) and non-integer values ("½") are left as-is, a disclosed limit. NFKC already handles the
     * plain circled digits ("①"-"⑨", "⓪") ahead of this step, via their own compatibility decomposition; this is
     * a no-op for anything NFKC already folded.
     */
    private function foldEnclosedDigits(string $text): string
    {
        return $this->orFail(preg_replace_callback('~\p{No}~u', static function (array $match): string {
            $value = IntlChar::getNumericValue($match[0]);

            // A single \p{No} code point always has a defined Numeric_Value in ICU, so
            // IntlChar::getNumericValue() cannot fail here; the sentinel for "no numeric value"
            // (U_NO_NUMERIC_VALUE, a large negative float) is excluded by the >= 0 bound below,
            // same as any other non-integer or out-of-range value ("½", "➉" = ten).
            if ($value === floor($value) && $value >= 0 && $value <= 9) {
                return (string) (int) $value;
            }

            return $match[0];
        }, $text));
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
