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
 *  - A run of digit words and typed numbers ("zero five 0 one two three four") becomes digits, one digit per word
 *    ("double five" is two digits), the gaps (up to six non-letter characters, counted as code points here where the chain's phone gap counts graphemes) kept, so the chain's phone-run budget applies exactly as it does to
 *    typed digits. The run needs at least one word and seven digits in all (the chain's own floor); anything
 *    shorter could not be hidden by the chain anyway.
 *  - A zero-width joiner between two letters or digits is dropped from the copy, so "th\u{200D}ree" reads as "three".
 *  - A messenger name followed within three words by a handle or a run of seven or more digits is hidden outright
 *    (`messengerSpans`), not rewritten to a marker: a marker placed after "-", "." or "+" falls outside EMAIL's
 *    lookbehind and would leave the handle visible. Names that are also ordinary words ("signal", "imo", "snap")
 *    only count when the handle or number comes straight after them.
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

    /** The chain's phone floor (`MessageMasker::MIN_PHONE_DIGITS`): fewer digits than this are never a phone number. */
    private const MIN_DIGITS = 7;

    /** Messenger names that are not ordinary words: a handle or number may follow after up to three words. */
    private const MESSENGERS = 'whatsapp|whats\h?app|telegram|discord|skype|instagram|snapchat|facebook|fb|messenger|botim|viber|wechat|tiktok';

    /** Messenger names that are also ordinary words ("signal", "imo", "snap"): only a handle or number right after them. */
    private const AMBIGUOUS_MESSENGERS = 'signal|insta|ig|snap|imo|kik';

    private const WORD_END = '(?![\p{L}\p{Nd}])';

    private const DOT_LEFT_GAP = '[\h\v(\[{<_*\-]{1,4}';

    private const DOT_RIGHT_GAP = '[\h\v)\]}>_*\-]{1,4}';

    /**
     * The text after a "dot" that makes it part of a domain: a recognised ending, one of COUNTRY_CODES, or a label
     * that is itself followed by another "dot" ("gmail dot co dot uk").
     */
    private const DOT_RIGHT = '(?=(?:'.MessageMasker::NON_WORD_ENDINGS.'|'.MessageMasker::WORD_ENDINGS.'|'.self::COUNTRY_CODES.')'.self::WORD_END
        .'|[\p{L}\p{Nd}]++'.self::DOT_LEFT_GAP.'dot'.self::WORD_END.')';

    private const DOT = '~(?<![\p{L}\p{Nd}])([\p{L}\p{Nd}]++)('.self::DOT_LEFT_GAP.'dot'.self::DOT_RIGHT_GAP.')'.self::DOT_RIGHT.'~iu';

    private const AT = '~(?<=[\p{L}\p{Nd}])(?:[\h\v]++at[\h\v]++(?:the[\h\v]++rate[\h\v]++(?:of[\h\v]++)?)?|[\h\v]{0,3}[(\[{][\h\v]*at[\h\v]*[)\]}][\h\v]{0,3})(?='.MessageMasker::AT_TARGET.')~iu';

    /** A zero-width joiner between two letters or digits splits a word the sender meant whole ("th\u{200D}ree"). */
    private const JOINER = '~(?<=[\p{L}\p{Nd}])\x{200D}(?=[\p{L}\p{Nd}])~u';

    private const DIGIT_WORD = '(?:(?:double|triple)[\h\v]++)?(?:zero|oh|one|two|three|four|five|six|seven|eight|nine)'.self::WORD_END;

    private const NUMBER = '\p{Nd}++(?!\p{L})';

    /**
     * Digit words and typed numbers, up to six separator characters apart (the chain's own phone gap; a separator is
     * anything but a letter, a digit or ":"); `digits` keeps the runs with a word in them.
     */
    private const DIGIT_RUN = '~(?<![\p{L}\p{Nd}])(?:'.self::DIGIT_WORD.'|'.self::NUMBER.')(?:[^\p{L}\p{Nd}:]{1,6}+(?:'.self::DIGIT_WORD.'|'.self::NUMBER.'))*+~iu';

    private const HANDLE_OR_NUMBER = '(?:@[\p{L}_][\p{L}\p{Nd}._]{2,}|\p{Nd}{'.self::MIN_DIGITS.',}+(?!\p{L}))';

    private const MESSENGER = '~(?<![\p{L}\p{Nd}])(?:'.self::MESSENGERS.'|'.self::AMBIGUOUS_MESSENGERS.')'.self::WORD_END.'~iu';

    private const MESSENGER_AMBIGUOUS = '~^(?:'.self::AMBIGUOUS_MESSENGERS.')$~iu';

    private const MESSENGER_TAIL = '~\G[^\p{L}\p{Nd}@]*+(?:\p{L}++[^\p{L}\p{Nd}@]*+){0,3}?'.self::HANDLE_OR_NUMBER.'~u';

    private const MESSENGER_TAIL_NEAR = '~\G[^\p{L}\p{Nd}@]*+'.self::HANDLE_OR_NUMBER.'~u';

    /**
     * The copy of `$text` with every spelled-out "dot", "at" and digit word rewritten, or null when nothing was.
     * `$text` is the normalised body the chain would see.
     */
    public static function rewrite(string $text): ?Shadow
    {
        $shadow = Shadow::identity($text);
        $changed = false;

        foreach (['joiners', 'dots', 'ats', 'digits'] as $step) {
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

            $near = preg_match(self::MESSENGER_AMBIGUOUS, $name) === 1;
            $tail = preg_match($near ? self::MESSENGER_TAIL_NEAR : self::MESSENGER_TAIL, $text, $found, PREG_OFFSET_CAPTURE, $end);

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
    private static function joiners(string $text): array
    {
        return array_map(
            static fn (array $match): array => [$match[1], strlen($match[0]), []],
            self::matches(self::JOINER, $text),
        );
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

    /**
     * Typed digits are counted as code points (`mb_strlen`), which is how the chain's phone floor (`\p{Nd}` matches against MIN_PHONE_DIGITS) counts them
     * too; the separators between tokens are code points as well, where the chain's phone gap counts graphemes.
     *
     * @return list<array{int, int, list<array{string, int, int}>}>
     */
    private static function digits(string $text): array
    {
        $edits = [];

        foreach (self::matches(self::DIGIT_RUN, $text) as [$run, $runOffset]) {
            $edit = [];
            $digits = 0;

            foreach (self::matches('~'.self::DIGIT_WORD.'|'.self::NUMBER.'~iu', $run) as [$token, $offset]) {
                if (preg_match('~^\p{Nd}~u', $token) === 1) {
                    $digits += mb_strlen($token);

                    continue;
                }

                $value = self::digitsOf($token);
                $digits += strlen($value);
                $edit[] = [$runOffset + $offset, strlen($token), [[$value, 0, strlen($token)]]];
            }

            if ($edit !== [] && $digits >= self::MIN_DIGITS) {
                array_push($edits, ...$edit);
            }
        }

        return $edits;
    }

    private static function digitsOf(string $word): string
    {
        if (preg_match('~^(?:(double|triple)[\h\v]++)?([a-z]+)$~u', strtolower($word), $parts) !== 1) {
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
