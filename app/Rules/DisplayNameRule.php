<?php

namespace App\Rules;

use App\Exceptions\MessageMaskingFailedException;
use App\Support\Messaging\MessageMasker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use IntlChar;
use Normalizer;

/**
 * R188(a), rebuilt on rules by R192 after three review rounds on a hand-made list, and since R195 the first
 * gate of the admin-approved display name: a tutor-chosen name that parents see only once an admin approves
 * it. The invariant it protects is "the text on screen is the text the contact-detail masker read"
 * (invariant 8), and it is enforced by construction, not by listing bad characters:
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
 *  5. the name must already be in NFKC, the form the masker reads (`normalise()` folds every honest input
 *     on the way in), so a compatibility character cannot expand into something the masker never saw;
 *  6. one script per name (Han, Hiragana and Katakana count as one), marks included: the masker's endings
 *     are ASCII, so a Cyrillic or Greek look-alike spelling "com" would pass it unseen;
 *  7. the masker must find nothing in the name as written, without its zero-width joiners, without its
 *     joiners and marks, or with the modifier letters it reads as word characters (ʼ ʻ ʾ ʿ and ー) folded to
 *     the apostrophe and hyphen they stand for (fail-closed, as the masker itself is);
 *  8. fewer than seven numeral characters (Han numerals, which carry a numeric value in ICU, and the
 *     Sino-Korean digit syllables): a full mobile number fits in 30 code points in Han or Hangul, which Latin
 *     cannot do.
 *
 * What it does NOT decide: taste, impersonation, and a short word before a short ending ("Ali. co",
 * "Bob. io", "Wa. me"), which the masker reads as a name. That is the admin's job at approval (R195); the
 * admin only ever sees names that already passed this rule.
 *
 * Known false refusals, all in the safe direction: a zero-width non-joiner (U+200C, which the masker strips),
 * the middle dots U+00B7 and U+30FB, the Hebrew geresh U+05F3, a full stop inside a word ("Bo.Li", "A.Rahman"),
 * the Arabic tatweel and other modifier letters, a full stop and space after a word of four letters or more
 * ("Prof. Ali"), two or more marks followed by exactly two closing letters (the masker reads the marks as a dot
 * and the two letters as a country code), a name in two scripts ("Wang 王", Hangul with Hanja), a hyphenated
 * name the masker's country-code heuristic reads as an address ("Mary-Jo"), a name of seven or more numeral
 * characters, and scripts outside LETTER (e.g. Armenian, Ethiopic).
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
    public const MARK = '(?:(?=[\p{Mn}\p{Mc}])(?![\x{0334}-\x{0338}\x{1CD0}-\x{1CFF}\x{20D0}-\x{20FF}\x{A8E0}-\x{A8FF}\x{302A}-\x{302D}\x{102E0}\x{11300}-\x{1137F}\x{1D200}-\x{1D24F}\x{16FF0}-\x{16FF1}])[\x{0300}-\x{036F}\x{1AB0}-\x{1ACE}\x{1DC0}-\x{1DFF}\x{FE20}-\x{FE2F}\x{3099}\x{309A}\x{064B}-\x{0655}\x{0670}\x{0951}-\x{0954}\p{Latin}\p{Greek}\p{Cyrillic}\p{Arabic}\p{Hebrew}\p{Devanagari}\p{Bengali}\p{Gurmukhi}\p{Gujarati}\p{Tamil}\p{Telugu}\p{Kannada}\p{Malayalam}\p{Sinhala}\p{Thai}\p{Han}\p{Hiragana}\p{Katakana}])';

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

    public const CHARACTERS_MESSAGE = 'A display name can only use letters of one script, spaces, full stops, apostrophes and hyphens.';

    public const CONTACT_MESSAGE = 'A display name cannot contain an email address, phone number or link.';

    public const LENGTH_MESSAGE = 'A display name must be 2 to 30 characters.';

    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 30;

    /** Han, Hiragana and Katakana are one script group: Japanese names mix them. Every other script stands alone. */
    private const JAPANESE = ['Han', 'Hiragana', 'Katakana'];

    /** The prolonged sound mark is Common in Unicode, but inside a name it belongs to Japanese. */
    private const PROLONGED_SOUND_MARK = "\u{30FC}";

    /** The digits 0 to 9 in Sino-Korean syllables: ICU gives Hangul syllables no numeric value, so they are listed. */
    private const HANGUL_NUMERALS = ['공', '영', '일', '이', '삼', '사', '오', '육', '륙', '칠', '팔', '구'];

    /** A name with this many numeral characters or more is refused (a mobile number is ten or eleven digits). */
    private const NUMERAL_LIMIT = 7;

    /** Modifier letters the masker reads as word characters, folded to the separator they stand for. */
    private const MASKER_FOLDS = ["\u{02BB}" => "'", "\u{02BC}" => "'", "\u{02BE}" => "'", "\u{02BF}" => "'", "\u{30FC}" => '-'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $failure = self::failure($value);

        if ($failure !== null) {
            $fail($failure);
        }
    }

    /**
     * The form a name is stored and shown in: surrounding whitespace trimmed, then folded to NFKC, the form
     * `MessageMasker` reads. Run on the way in (the Form Request and `RequestDisplayName`), so the validator
     * and the masker judge the same code points a parent will see. Invalid UTF-8 is returned unchanged and
     * refused by `refusal()`.
     */
    public static function normalise(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            return $value;
        }

        $folded = Normalizer::normalize(trim($value), Normalizer::FORM_KC);

        return $folded === false ? $value : trim($folded);
    }

    /**
     * The one yes/no for "may this name be shown to a parent": length, the structural rules and the masker.
     * `ApproveDisplayName` calls it at the moment of approval, so a request stored under an older rule can
     * never reach `display_name`.
     */
    public static function accepts(string $value): bool
    {
        return self::failure($value) === null;
    }

    /** Why a name may not be shown to a parent (length, characters, then contact details), or null when it may. */
    public static function failure(string $value): ?string
    {
        $length = self::length($value);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return self::LENGTH_MESSAGE;
        }

        if (self::refusal($value) !== null) {
            return self::CHARACTERS_MESSAGE;
        }

        return self::masked($value) ? self::CONTACT_MESSAGE : null;
    }

    /** Code points, not bytes or graphemes: marks and joiners count, so a name cannot be padded invisibly. */
    private static function length(string $value): int
    {
        return (int) preg_match_all('/./su', $value);
    }

    /** The structural rules (everything except the masker and the length): null when the name passes, else why not. */
    public static function refusal(string $value): ?string
    {
        if (preg_match('//u', $value) !== 1) {
            return 'invalid UTF-8';
        }

        if (preg_match(MessageMasker::INVISIBLE, $value) !== 0) {
            return 'a character the masker strips';
        }

        // The masker reads the NFKC form. A name that is not already in it (a compatibility character that
        // expands into a separator, a digit, an at sign or a longer word: "R\u{0133}k" is "Rijk") is not what
        // the masker read, so it is refused; `normalise()` has folded every honest input before it gets here.
        if (! Normalizer::isNormalized($value, Normalizer::FORM_KC)) {
            return 'not in NFKC form';
        }

        if (preg_match(self::NAME_PATTERN, $value) !== 1) {
            return 'characters outside the admitted scripts, or a misplaced separator';
        }

        if (preg_match(self::MARK_RUN_PATTERN, $value) !== 0) {
            return 'too many marks or joiners in a row';
        }

        // A zero-width joiner between two letters is legitimate in Indic and Persian names, but it must not
        // split a long word so that the next check no longer sees four letters in a row ("Sa\u{200D}rah. co").
        if (preg_match(self::LONG_WORD_STOP_PATTERN, self::withoutJoiners($value)) !== 0) {
            return 'a full stop and space after a long word';
        }

        if (preg_match_all('/'.self::BASE.'/u', $value) < 2) {
            return 'fewer than two letters';
        }

        // Seven or more numeral characters: a mobile number written in Han or Hangul numerals fits in 30 code
        // points. Latin spelled-out numbers do not, and stay in the admitted class (ADR-019, ADR-027).
        if (self::numerals($value) >= self::NUMERAL_LIMIT) {
            return 'seven or more numerals';
        }

        // One script per name. The masker's endings are ASCII, so a Cyrillic or Greek look-alike spelling a
        // domain ending ("Ali. \u{0441}\u{043E}\u{043C}") is invisible to it; a name that mixes scripts is
        // refused instead of judged. Common characters (space, hyphen, apostrophe, full stop) and the marks
        // belong to no script of their own.
        if (self::scriptGroups($value) > 1) {
            return 'letters from more than one script';
        }

        return null;
    }

    private static function withoutJoiners(string $value): string
    {
        return str_replace("\u{200D}", '', $value);
    }

    /** How many characters of the name are numerals: a numeric value in ICU (Han) or a Sino-Korean digit syllable. */
    private static function numerals(string $value): int
    {
        $count = 0;

        foreach (mb_str_split($value, 1, 'UTF-8') as $char) {
            if (IntlChar::getNumericValue(mb_ord($char, 'UTF-8')) !== IntlChar::NO_NUMERIC_VALUE || in_array($char, self::HANGUL_NUMERALS, true)) {
                $count++;
            }
        }

        return $count;
    }

    /** How many script groups the name's letters and marks come from (Han, Hiragana and Katakana count as one). */
    private static function scriptGroups(string $value): int
    {
        $groups = [];

        foreach (mb_str_split($value, 1, 'UTF-8') as $char) {
            if (preg_match('/^(?:'.self::LETTER.'|'.self::MARK.')$/u', $char) !== 1) {
                continue;
            }

            if ($char === self::PROLONGED_SOUND_MARK) {
                $groups['Japanese'] = true;

                continue;
            }

            $script = IntlChar::getPropertyValueName(
                IntlChar::PROPERTY_SCRIPT,
                IntlChar::getIntPropertyValue(mb_ord($char, 'UTF-8'), IntlChar::PROPERTY_SCRIPT),
                IntlChar::LONG_PROPERTY_NAME,
            );

            // Inherited and Common belong to no script: the generic diacritics, the apostrophe-like letters.
            if (in_array($script, ['Inherited', 'Common', 'Unknown'], true)) {
                continue;
            }

            $groups[in_array($script, self::JAPANESE, true) ? 'Japanese' : $script] = true;
        }

        return count($groups);
    }

    /**
     * Fail-closed like the masker itself (R157(a)): if the masker cannot decide, treat the name as masked.
     * It reads the name in five forms: as written; without the zero-width joiners (so a joiner cannot break a
     * word the masker would otherwise match); without the joiners and marks (so a mark cannot take the place
     * of a dot or a gap); and the last two with the modifier letters folded to the apostrophe and hyphen they
     * stand for (the masker reads them as word characters, so "mysite" + U+02BC + "com" passes it unseen).
     */
    private static function masked(string $value): bool
    {
        $noJoiners = self::withoutJoiners($value);
        $bare = preg_replace('/'.self::MARK.'/u', '', $noJoiners);

        try {
            $bare ??= $noJoiners;
            $forms = [$value, $noJoiners, $bare, strtr($noJoiners, self::MASKER_FOLDS), strtr($bare, self::MASKER_FOLDS)];

            foreach (array_unique($forms) as $form) {
                if (app(MessageMasker::class)->mask($form)->masked) {
                    return true;
                }
            }

            return false;
        } catch (MessageMaskingFailedException) {
            return true;
        }
    }
}
