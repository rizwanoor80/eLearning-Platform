<?php

namespace App\Rules;

use App\Exceptions\MessageMaskingFailedException;
use App\Support\Messaging\MessageMasker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Normalizer;

/**
 * R188(a), rebuilt on rules by R192 after three review rounds on a hand-made list: a tutor-chosen display
 * name that parents see. The invariant it protects is "the text on screen is the text the contact-detail
 * masker read" (invariant 8), and it is enforced by construction, not by listing bad characters:
 *
 *  1. every code point `MessageMasker` strips before matching is refused — read from the masker's own
 *     `INVISIBLE` constant, never a copy, so the two cannot drift;
 *  2. only letters of named scripts are admitted (see LETTER), and marks only on those scripts or as
 *     generic combining diacritics (see MARK) — not "every letter and mark" — and nothing else: no digits,
 *     no symbols, no `@`, no direction controls;
 *  3. a full stop is allowed only after a word and before a space or the end (Dr. Sara, Mohammed A. Khan),
 *     never between two words, so a name can never read as a domain;
 *  4. marks and joiners are counted through each other: more than five in a row are refused, and the
 *     2–30 length counts every code point, marks and joiners included, so a name cannot be padded invisibly;
 *  5. the name must also keep its shape after the masker's own NFKC folding, and the masker must find
 *     nothing in it (fail-closed, as the masker itself is).
 *
 * Known false refusals, all in the safe direction: a zero-width non-joiner (U+200C, which the masker strips),
 * the middle dots U+00B7 and U+30FB, the Hebrew geresh U+05F3, a full stop inside a word ("Bo.Li", "A.Rahman"),
 * the Arabic tatweel and other modifier letters, a full stop and space after a word of four letters or more
 * ("Prof. Ali"), two or more marks followed by exactly two closing letters (the masker reads the marks as a dot
 * and the two letters as a country code), and scripts outside LETTER (e.g. Armenian, Ethiopic).
 */
class DisplayNameRule implements ValidationRule
{
    /**
     * Blocks inside the admitted scripts that hold no everyday letters but do hold dot- and sign-shaped
     * "letters" (found by scanning every admitted code point by name, CYCLE-LOG 14b round 4): Arabic
     * Extended-B (U+0887 is a literal baseline dot), the Arabic presentation forms and mathematical
     * letters (a keyboard types the base letters), the Vedic and Devanagari extensions, the CJK symbols
     * and the Ideographic Symbols block (U+3005 stays: it is the Japanese iteration mark in names), and
     * the Tamil visarga U+0B83, and the Bengali anji and Kannada candrabindu (dot-over-crescent signs).
     */
    private const NOT_LETTER_BLOCKS = '\x{0870}-\x{089F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}\x{1EE00}-\x{1EEFF}\x{1CD0}-\x{1CFF}\x{A8E0}-\x{A8FF}\x{16FE0}-\x{16FFF}\x{3000}-\x{3004}\x{3006}-\x{303F}\x{0B83}\x{0980}\x{0C80}';

    /**
     * Letters, by script. Cased scripts admit only Lu/Ll/Lt in their main blocks (so the click letters
     * U+01C0–01C3, IPA and Latin-extended Lo/Lm letters that draw as punctuation are out as a rule); the
     * caseless scripts admit Lo only; Hangul admits precomposed syllables only, never the Jamo blocks where
     * the invisible fillers live. Modifier letters (Lm) are out except the Han/Kana iteration and
     * long-vowel marks and the four apostrophe-like letters names use (ʻokina, ʼ, ʾ, ʿ).
     */
    public const LETTER = '(?:'.self::BASE.'|'.self::MODIFIER.')';

    /** The letters that can carry a name on their own: cased Lu/Ll/Lt, caseless Lo, Hangul syllables. */
    private const BASE = '(?:'
        .'(?=[\p{Lu}\p{Ll}\p{Lt}])[\x{0041}-\x{024F}\x{1E00}-\x{1EFF}\x{0370}-\x{03FF}\x{0400}-\x{04FF}]'
        .'|(?!['.self::NOT_LETTER_BLOCKS.'])(?=\p{Lo})[\p{Arabic}\x{05D0}-\x{05EA}\x{05F0}-\x{05F2}\p{Devanagari}\p{Bengali}\p{Gurmukhi}\p{Gujarati}\p{Tamil}\p{Telugu}\p{Kannada}\p{Malayalam}\p{Sinhala}\p{Thai}\p{Han}\p{Hiragana}\p{Katakana}]'
        .'|[\x{AC00}-\x{D7A3}]'
        .')';

    /**
     * The modifier letters that ride along with a name (the iteration and long-vowel marks of Han and
     * Kana, ʻokina and the apostrophe-like ʼ ʾ ʿ) but never make a word, or a name, by themselves.
     */
    private const MODIFIER = '(?:'
        .'(?!['.self::NOT_LETTER_BLOCKS.'])(?=\p{Lm})[\p{Han}\p{Hiragana}\p{Katakana}]'
        .'|[\x{30FC}\x{02BB}\x{02BC}\x{02BE}\x{02BF}]'
        .')';

    /**
     * Combining marks (Mn/Mc, never the enclosing Me): those of the admitted scripts, plus the generic
     * combining diacritics, listed by range (not \p{Inherited}, which also holds overlays, musical and Znamenny marks; PCRE matches script extensions, so the Vedic-tone and enclosing-overlay blocks, and the strike/solidus overlays U+0334–0338, are carved out). Khmer, Mongolian, Khitan and Hangul-tone marks are
     * not in the list, so the invisible ones among them are out by script; the variation selectors and the
     * combining grapheme joiner are Inherited and are caught by rule 1 instead.
     */
    public const MARK = '(?:(?=[\p{Mn}\p{Mc}])(?![\x{0334}-\x{0338}\x{1CD0}-\x{1CFF}\x{20D0}-\x{20FF}])[\x{0300}-\x{036F}\x{1AB0}-\x{1ACE}\x{1DC0}-\x{1DFF}\x{FE20}-\x{FE2F}\x{3099}\x{309A}\x{064B}-\x{0655}\x{0670}\x{0951}-\x{0954}\p{Latin}\p{Greek}\p{Cyrillic}\p{Arabic}\p{Hebrew}\p{Devanagari}\p{Bengali}\p{Gurmukhi}\p{Gujarati}\p{Tamil}\p{Telugu}\p{Kannada}\p{Malayalam}\p{Sinhala}\p{Thai}\p{Han}\p{Hiragana}\p{Katakana}])';

    /**
     * A word: letters, each with its marks; a zero-width joiner only between two letters (Indic conjuncts).
     * Never a word of modifier letters alone ("ʼʼ", "ーー"): the lookahead refuses a run of modifiers that no
     * other letter follows.
     */
    private const WORD = '(?!(?:'.self::MODIFIER.self::MARK.'*)+(?!'.self::LETTER.'))(?:'.self::LETTER.'(?:'.self::MARK.'|\x{200D}(?='.self::LETTER.'))*)+';

    /** Words separated by one space, hyphen or apostrophe, or by ". "; an optional full stop at the end. */
    public const NAME_PATTERN = '/^'.self::WORD.'(?:(?:[ \-\'’]|\. )'.self::WORD.')*\.?\z/u';

    /**
     * A full stop followed by a space after a word of four letters or more ("Sara. com", "Khan. net"): the
     * ". " separator is for titles and initials (Dr. Ms. Mrs. A.), and the masker, which reads "Sara. com"
     * as an address but "Sara. co" as a name, is not left to judge a domain split by a space.
     */
    private const LONG_WORD_STOP_PATTERN = '/(?:'.self::LETTER.self::MARK.'*){4,}\. /u';

    /** More marks and joiners in a row than any real name needs (pointed Hebrew with cantillation: four). */
    private const MARK_RUN_PATTERN = '/(?:'.self::MARK.'|\x{200D}){6,}/u';

    public const CHARACTERS_MESSAGE = 'A display name can only use letters, spaces, full stops, apostrophes and hyphens.';

    public const CONTACT_MESSAGE = 'A display name cannot contain an email address, phone number or link.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        if (self::refusal($value) !== null) {
            $fail(self::CHARACTERS_MESSAGE);

            return;
        }

        if (self::masked($value)) {
            $fail(self::CONTACT_MESSAGE);
        }
    }

    /**
     * The one yes/no for "may a parent see this stored name": the structural rules and the masker.
     * `TutorProfile::displayName()` falls back to the first-name default when this is false, so a value
     * written under any older rule can never reach a parent.
     */
    public static function accepts(string $value): bool
    {
        return self::refusal($value) === null && ! self::masked($value);
    }

    /** The structural rules (1 to 5 except the masker): null when the name passes, else why not. */
    public static function refusal(string $value): ?string
    {
        if (preg_match('//u', $value) !== 1) {
            return 'invalid UTF-8';
        }

        if (preg_match(MessageMasker::INVISIBLE, $value) !== 0) {
            return 'a character the masker strips';
        }

        if (preg_match(self::NAME_PATTERN, $value) !== 1) {
            return 'characters outside the admitted scripts, or a misplaced separator';
        }

        if (preg_match(self::MARK_RUN_PATTERN, $value) !== 0) {
            return 'too many marks or joiners in a row';
        }

        if (preg_match(self::LONG_WORD_STOP_PATTERN, $value) !== 0) {
            return 'a full stop and space after a long word';
        }

        if (preg_match_all('/'.self::BASE.'/u', $value) < 2) {
            return 'fewer than two letters';
        }

        // The masker reads the NFKC form; a name whose folded form is no longer a name (a compatibility
        // character that expands into a separator, a digit or an at sign) is not what the parent sees.
        $folded = Normalizer::normalize($value, Normalizer::FORM_KC);

        if ($folded === false || preg_match(self::NAME_PATTERN, $folded) !== 1) {
            return 'changes shape when normalised';
        }

        return null;
    }

    /** Fail-closed like the masker itself (R157(a)): if the masker cannot decide, treat the name as masked. */
    private static function masked(string $value): bool
    {
        try {
            return app(MessageMasker::class)->mask($value)->masked;
        } catch (MessageMaskingFailedException) {
            return true;
        }
    }
}
