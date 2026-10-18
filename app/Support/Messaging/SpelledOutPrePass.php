<?php

namespace App\Support\Messaging;

use App\Exceptions\MessageMaskingFailedException;

/**
 * R231: the shapes a sender can write to get a contact detail past `MessageMasker`'s chain without any of the
 * characters the chain looks for — "sara at gmail dot com", "zero five zero one two three four five six", "whatsapp
 *
 * @sara_k". One normalising pre-pass on a COPY of the body (a `Shadow`), never new patterns inside the chain: the
 * copy is rewritten to the plain shape ("sara@gmail.com", "050123456"), the unchanged chain runs over it, and
 * `MessageMasker` maps what the chain hid back onto the original text, so the stored body shows the sender's own
 * words everywhere except where something was hidden.
 *
 *  - `dot`, `(dot)`, `[dot]`, `{dot}` between a label and a recognised ending (or another `dot` after a label)
 *    become ".". The word needs a non-empty gap on both sides ("dotted", "polka" are never touched), the left label
 *    must not be a plain English word that makes "the dot com bubble" read as a domain (STOP_LABELS), and only a
 *    short list of country codes counts as an ending, so "a red dot on the map" survives.
 *  - `at`, `(at)`, `[at]`, `{at}` become "@" only when the text after it has the SHAPE of a mail target, built
 *    from the chain's own constants (`MessageMasker::AT_TARGET`: a mail provider, or labels and a recognised
 *    ending): "I am at home. Also fine" is untouched, "sara at gmail" is not.
 *  - A run of five or more English digit words becomes digits, one digit per word, the gaps kept ("double five" is
 *    two digits), so the chain's phone-run budget applies exactly as it does to typed digits. Four words or fewer
 *    are left alone: they could not reach the chain's seven-digit floor on their own.
 *  - A messenger name followed within three words by a handle or a run of five or more digits is hidden outright
 *    (`messengerSpans`), not rewritten to a marker: a marker placed after "-", "." or "+" falls outside EMAIL's
 *    lookbehind and would leave the handle visible.
 *
 * Pure; no I/O. A PCRE failure refuses the message exactly as it does in the chain.
 */
final class SpelledOutPrePass
{
    /** Left labels that make a spelled "dot" ordinary prose ("the dot com bubble", "a dot net developer"). */
    private const STOP_LABELS = ['the', 'a', 'an', 'this', 'that', 'polka', 'decimal', 'red', 'full', 'every', 'each'];

    /** The country codes a spelled "dot" may end on: the short list of ones that are not common English words. */
    private const COUNTRY_CODES = 'ae|uk|pk|sa|eg|qa|kw|bh|om|jo|lb|au|nz|za|io|co|tv|cc|fm|sg|ph|cn|jp|kr|fr|de|ru|br|mx|me|us|ca';

    private const DIGIT_WORDS = ['zero' => '0', 'oh' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9'];

    private const MIN_DIGIT_WORDS = 5;

    private const MESSENGERS = 'whatsapp|whats\h?app|telegram|signal|discord|skype|instagram|insta|ig|snapchat|snap|facebook|fb|messenger|botim|imo|viber|wechat|kik|tiktok';

    private const MIN_HANDLE_DIGITS = 5;

    private const WORD_END = '(?![\p{L}\p{Nd}])';

    private const DOT_LEFT_GAP = '[\h(\[{<_*\-]{1,4}';

    private const DOT_RIGHT_GAP = '[\h)\]}>_*\-]{1,4}';

    /**
     * The text after a "dot" that makes it part of a domain: a recognised ending, one of COUNTRY_CODES, or a label
     * that is itself followed by another "dot" ("gmail dot co dot uk").
     */
    private const DOT_RIGHT = '(?=(?:'.MessageMasker::NON_WORD_ENDINGS.'|'.MessageMasker::WORD_ENDINGS.'|'.self::COUNTRY_CODES.')'.self::WORD_END
        .'|[\p{L}\p{Nd}]++'.self::DOT_LEFT_GAP.'dot'.self::WORD_END.')';

    private const DOT = '~(?<![\p{L}\p{Nd}])([\p{L}\p{Nd}]++)('.self::DOT_LEFT_GAP.'dot'.self::DOT_RIGHT_GAP.')'.self::DOT_RIGHT.'~iu';

    private const AT = '~(?<=[\p{L}\p{Nd}])(?:\h++at\h++|\h{0,3}[(\[{]\h*at\h*[)\]}]\h{0,3})(?='.MessageMasker::AT_TARGET.')~iu';

    private const DIGIT_WORD = '(?:(?:double|triple)\h++)?(?:zero|oh|one|two|three|four|five|six|seven|eight|nine)'.self::WORD_END;

    private const DIGIT_RUN = '~(?<![\p{L}\p{Nd}])'.self::DIGIT_WORD.'(?:[\h,.\-]{1,3}+'.self::DIGIT_WORD.'){'.(self::MIN_DIGIT_WORDS - 1).',}~iu';

    private const MESSENGER = '~(?<![\p{L}\p{Nd}])(?:'.self::MESSENGERS.')'.self::WORD_END.'~iu';

    private const MESSENGER_TAIL = '~\G[^\p{L}\p{Nd}@]*+(?:\p{L}++[^\p{L}\p{Nd}@]*+){0,3}?(?:@[\p{L}_][\p{L}\p{Nd}._]{2,}|\p{Nd}{'.self::MIN_HANDLE_DIGITS.',})~u';

    /**
     * The copy of `$text` with every spelled-out "dot", "at" and digit word rewritten, or null when nothing was.
     * `$text` is the normalised body the chain would see.
     */
    public static function rewrite(string $text): ?Shadow
    {
        $shadow = Shadow::identity($text);
        $changed = false;

        foreach (['dots', 'ats', 'digits'] as $step) {
            $edits = self::$step($shadow->text);

            if ($edits !== []) {
                $shadow = $shadow->apply($edits);
                $changed = true;
            }
        }

        return $changed ? $shadow : null;
    }

    /**
     * The byte ranges of `$text` to hide outright: from a messenger name to the end of the handle or digits that
     * follow it.
     *
     * @return list<array{int, int}>
     */
    public static function messengerSpans(string $text): array
    {
        $names = self::matches(self::MESSENGER, $text);
        $spans = [];
        $position = 0;

        foreach ($names as [$name, $offset]) {
            $end = $offset + strlen($name);

            if ($offset < $position) {
                continue;
            }

            $tail = preg_match(self::MESSENGER_TAIL, $text, $found, PREG_OFFSET_CAPTURE, $end);

            if ($tail === false) {
                throw new MessageMaskingFailedException(MessageMaskingFailedException::NOTICE);
            }

            if ($tail === 1) {
                $position = $found[0][1] + strlen($found[0][0]);
                $spans[] = [$offset, $position];
            }
        }

        return $spans;
    }

    /** @return list<array{int, int, list<array{string, int, int}>}> */
    private static function dots(string $text): array
    {
        $edits = [];

        foreach (self::matches(self::DOT, $text, true) as [, , $label, $gap, $gapOffset]) {
            if (in_array(strtolower($label), self::STOP_LABELS, true)) {
                continue;
            }

            $edits[] = [$gapOffset, strlen($gap), [['.', 0, strlen($gap)]]];
        }

        return $edits;
    }

    /** @return list<array{int, int, list<array{string, int, int}>}> */
    private static function ats(string $text): array
    {
        $edits = [];

        foreach (self::matches(self::AT, $text) as [$match, $offset]) {
            $edits[] = [$offset, strlen($match), [['@', 0, strlen($match)]]];
        }

        return $edits;
    }

    /** @return list<array{int, int, list<array{string, int, int}>}> */
    private static function digits(string $text): array
    {
        $edits = [];

        foreach (self::matches(self::DIGIT_RUN, $text) as [$run, $runOffset]) {
            foreach (self::matches('~'.self::DIGIT_WORD.'~iu', $run) as [$word, $offset]) {
                $edits[] = [$runOffset + $offset, strlen($word), [[self::digitsOf($word), 0, strlen($word)]]];
            }
        }

        return $edits;
    }

    private static function digitsOf(string $word): string
    {
        if (preg_match('~^(?:(double|triple)\h++)?([a-z]+)$~', strtolower($word), $parts) !== 1) {
            throw new MessageMaskingFailedException(MessageMaskingFailedException::NOTICE);
        }

        $digit = self::DIGIT_WORDS[$parts[2]];

        return match ($parts[1]) {
            'double' => $digit.$digit,
            'triple' => $digit.$digit.$digit,
            default => $digit,
        };
    }

    /**
     * Every match of `$pattern` as `[text, offset]`; with `$groups` as `[text, offset, label, gap, gapOffset]`, the
     * pair of capture groups the dot pattern needs.
     *
     * @return list<array<int, mixed>>
     */
    private static function matches(string $pattern, string $text, bool $groups = false): array
    {
        $count = preg_match_all($pattern, $text, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        if ($count === false) {
            throw new MessageMaskingFailedException(MessageMaskingFailedException::NOTICE);
        }

        return array_map(
            static fn (array $match): array => $groups
                ? [$match[0][0], $match[0][1], $match[1][0], $match[2][0], $match[2][1]]
                : [$match[0][0], $match[0][1]],
            $found,
        );
    }
}
