<?php

use App\Exceptions\MessageMaskingFailedException;
use App\Rules\DisplayNameRule;
use App\Support\Messaging\MaskedMessage;
use App\Support\Messaging\MessageMasker;
use Database\Seeders\DemoTutorSeeder;

/**
 * R192, rebuilt for R195 (15c): the display-name rule is built on rules, not a list. It is the first gate of
 * the admin-approved display name, so the admin only ever reads names that already passed it. Fixtures are
 * every bypass the earlier review rounds found, plus sweeps over the whole code space that fail if the rule
 * and the masker diverge.
 */
function dnrCodePoints(): Generator
{
    for ($c = 0; $c <= 0x10FFFF; $c++) {
        if ($c >= 0xD800 && $c <= 0xDFFF) {
            continue;
        }

        yield $c => mb_chr($c, 'UTF-8');
    }
}

it('refuses every code point the masker strips, read from the masker own constant', function () {
    $stripped = 0;

    foreach (dnrCodePoints() as $c => $char) {
        if (preg_match(MessageMasker::INVISIBLE, $char) !== 1) {
            continue;
        }

        $stripped++;

        expect(DisplayNameRule::refusal("Sara{$char}Khan"))->not->toBeNull(sprintf('U+%04X is stripped by the masker but not refused', $c));
    }

    // Guard: if the constant ever stopped matching anything, the loop above would pass vacuously.
    expect($stripped)->toBeGreaterThan(150);
});

it('admits no letter or mark that draws as punctuation, a dot or a blank', function () {
    $letter = '/^'.DisplayNameRule::LETTER.'$/u';
    $mark = '/^'.DisplayNameRule::MARK.'$/u';
    $shaped = '/\b(BASELINE|FULL STOP|PERIOD|COLON|CLICK|DIVISION|AT SIGN|SOLIDUS|SLASH|FILLER|SPACE|BLANK|ENCLOSING)\b/';
    $letters = 0;
    $marks = 0;
    $admitted = [];

    foreach (dnrCodePoints() as $c => $char) {
        $isLetter = preg_match($letter, $char) === 1;
        $isMark = preg_match($mark, $char) === 1;

        if (! $isLetter && ! $isMark) {
            continue;
        }

        $isLetter ? $letters++ : $marks++;
        $name = IntlChar::charName($c) ?: '';
        $type = IntlChar::charType($c);

        if (preg_match($shaped, $name) === 1
            || $type === IntlChar::CHAR_CATEGORY_ENCLOSING_MARK
            || $type === IntlChar::CHAR_CATEGORY_FORMAT_CHAR) {
            $admitted[] = sprintf('U+%04X %s', $c, $name);
        }
    }

    expect($admitted)->toBe([])->and($letters)->toBeGreaterThan(10000)->and($marks)->toBeGreaterThan(300);
});

// The consultation-1 advice was "refuse any code point with a numeric value". ICU gives the combining
// Devanagari digits U+A8E0–A8E9 no numeric value, and Han numerals (一, 二) do have one and are real name
// characters, so the guard is the general category over every admitted letter and mark, and the character
// name over every admitted mark (a mark has no business being a digit; letters such as an Arabic letter with
// "TWO DOTS ABOVE" are letters, not numbers).
it('admits no digit, number or numeral, by general category or by the name of a mark', function () {
    $letter = '/^'.DisplayNameRule::LETTER.'$/u';
    $mark = '/^'.DisplayNameRule::MARK.'$/u';
    $admitted = [];
    $seen = 0;

    foreach (dnrCodePoints() as $c => $char) {
        $isLetter = preg_match($letter, $char) === 1;
        $isMark = preg_match($mark, $char) === 1;

        if (! $isLetter && ! $isMark) {
            continue;
        }

        $seen++;
        $name = IntlChar::charName($c) ?: '';
        $type = IntlChar::charType($c);

        if ($type === IntlChar::CHAR_CATEGORY_DECIMAL_DIGIT_NUMBER
            || $type === IntlChar::CHAR_CATEGORY_LETTER_NUMBER
            || $type === IntlChar::CHAR_CATEGORY_OTHER_NUMBER
            || ($isMark && preg_match('/\b(DIGIT|NUMERAL)\b/', $name) === 1)) {
            $admitted[] = sprintf('U+%04X %s', $c, $name);
        }
    }

    expect($admitted)->toBe([])->and($seen)->toBeGreaterThan(10000);
});

it('refuses the combining Devanagari digits U+A8E0 to U+A8E9 after a letter', function () {
    foreach (range(0xA8E0, 0xA8E9) as $c) {
        expect(DisplayNameRule::refusal('Sara'.mb_chr($c, 'UTF-8')))->not->toBeNull(sprintf('U+%04X', $c));
    }
});

/**
 * One dataset entry per fixture. Pest hands every element of an entry to the closure as an argument, so a
 * group of fixtures under one label would be called as (first, second, ...) and a closure that takes one
 * value would only ever run the first: each fixture gets its own entry instead.
 *
 * @param  array<string, list<string>>  $groups
 * @return array<string, array{string}>
 */
function dnrEach(array $groups): array
{
    $flat = [];

    foreach ($groups as $label => $values) {
        foreach (array_values($values) as $i => $value) {
            $flat[count($values) > 1 ? $label.' #'.($i + 1) : $label] = [$value];
        }
    }

    return $flat;
}

// Both forms are judged: as written (what `ApproveDisplayName` re-reads from storage) and after `normalise()`
// (what the Form Request and `RequestDisplayName` hand the rule), so a fixture is caught by the check it
// names and not only by the NFKC gate.
it('refuses every bypass found in the review rounds, as written and once folded', function (string $value) {
    expect(DisplayNameRule::accepts($value))->toBeFalse()
        ->and(DisplayNameRule::accepts(DisplayNameRule::normalise($value)))->toBeFalse();
})->with(dnrEach([
    'direction override' => ["\u{202E}moc.liamg@aras"],
    'Lisu tone letters' => ["sarahtutor\u{A4F8}com", "sarahtutor\u{A4FD}com"],
    'Latin dot letter' => ["sarahtutor\u{A78F}com"],
    'enclosing circle' => ["saraa\u{20DD}gmail\u{A4F8}com"],
    'Mongolian variation selector' => ["sara.sch\u{180B}ool", "sara.sch\u{180F}ool"],
    'Khitan filler' => ["sara.tut\u{16FE4}ors"],
    'Hangul choseong filler' => ["sara.sch\u{115F}ool"],
    'Hangul jungseong filler' => ["sara.sch\u{1160}ool"],
    'Hangul filler' => ["mysite.com\u{3164}Tutor"],
    'halfwidth Hangul filler' => ["sara.sch\u{FFA0}ool"],
    'Khmer inherent vowels' => ["sara.sch\u{17B4}ool", "sara.sch\u{17B5}ool"],
    'filler after a letter and a hyphen' => ["A\u{3164}-"],
    'combining grapheme joiner' => ["sara.sch\u{034F}ool"],
    'Braille blank' => ["sara.sch\u{2800}ool"],
    'Canadian syllabics dot' => ["sarahtutor\u{1427}com", "sarahtutor\u{1429}com"],
    'Mongolian dot letter' => ["sarahtutor\u{18DF}com"],
    'Arabic baseline round dot' => ["sarahtutor\u{0887}com"],
    'Devanagari stress sign' => ["sarahtutor\u{0971}com"],
    'modifier letters' => ["sarahtutor\u{A717}com", "sarahtutor\u{A719}com", "sarahtutor\u{02D0}com", "sarahtutor\u{02D1}com", "sarahtutor\u{10781}com"],
    'click letters' => ["sarahtutor\u{01C0}com", "sarahtutor\u{01C3}com"],
    'zero-width space' => ["Sara\u{200B}Khan"],
    'word joiner' => ["Sara\u{2060}Khan"],
    'joiner stack with no letter to join' => ["Sa\u{0336}\u{200D}\u{0336}\u{200D}\u{0336}ra"],
    'six marks in a row' => ["Sa\u{0336}\u{0336}\u{0336}\u{0336}\u{0336}\u{0336}ra"],
    'mark with no letter' => ["\u{0301}Sara"],
    'domain' => ['sara.school'],
    'modifier letters alone' => ['ʼʼ', 'ʻʻ', 'ーー', '〱〱', 'ｰｰ', '々々', 'Sara ʼʼ'],
    'domain split by a space after a long word' => ['Sara. com', 'Sara. Com', 'Khan. net', 'Sara. co', 'Sara. ae', 'Sara. tutor'],
    'domain with a hyphen' => ['sara-tutor.com'],
    'full stop between words' => ['Sara.Khan'],
    'email' => ['sara@gmail.com'],
    'handle' => ['@sara_tutor'],
    'digits' => ['Sara 2'],
    'one letter' => ['A'],
    'one letter and a mark' => ["A\u{0301}"],
    'one letter and a hyphen' => ['A-'],
    'leading hyphen' => ['-Sara'],
    'doubled separator' => ['Sara  Khan'],
    'tab' => ["Sara\tKhan"],
    'invalid UTF-8' => ["Sara\xC3\x28Khan"],
    'modifier letters standing for an apostrophe, spelling a domain' => ["mysite\u{02BC}com", "gmail\u{02BB}com", "mysite\u{02BE}com", "mysite\u{02BF}com"],
    'prolonged sound mark between Latin words' => ["Sara\u{30FC}Khan", "sara\u{30FC}gmail\u{30FC}com", "mysite\u{30FC}com"],
    'a mobile number in Han numerals' => ['零五零一二三四五六七', '零五零一二三四'],
    'a mobile number in Sino-Korean numerals' => ['공일공일이삼사오육칠팔', '영오영 일이삼 사오육칠', '공일공일이삼사', '령일령일이삼사'],
]));

function dnrRound4(): array
{
    return dnrEach([
        'zero-width joiner splitting a long word before a domain ending' => ["Sa\u{200D}rah. co", "Sa\u{200D}rah. com", "Kha\u{200D}n. net"],
        'fullwidth full stop folds to a dot' => ["Sara\u{FF0E}com"],
        'ligature IJ folds to two letters' => ["R\u{0133}k"],
        'fullwidth at sign' => ["sara\u{FF20}gmail"],
        'fullwidth digit' => ["Sara\u{FF11}"],
        'circled digit' => ["Sara\u{2460}"],
        'superscript digit' => ["Sara\u{00B2}"],
        'Arabic-Indic digit' => ["Sara\u{0661}"],
        'Devanagari digit' => ["Sara\u{0967}"],
        'combining Devanagari digit' => ["Sara\u{A8E3}", "Sara\u{A8E9}"],
        'ideographic full stop' => ["Sara\u{3002}com"],
        'small full stop' => ["Sara\u{FE52}com"],
    ]);
}

it('refuses the round-4 bypasses as written: a joiner inside a long word, a compatibility character, a combining digit', function (string $value) {
    expect(DisplayNameRule::accepts($value))->toBeFalse();
})->with(dnrRound4());

// Folded, every one stays refused except the ligature: "R\u{0133}k" folds to "Rijk", an honest name.
it('refuses the round-4 bypasses once folded too, but not a ligature that folds to a plain name', function (string $value) {
    expect(DisplayNameRule::accepts(DisplayNameRule::normalise($value)))->toBeFalse();
})->with(fn () => array_diff_key(dnrRound4(), ['ligature IJ folds to two letters' => true]));

it('refuses a name that mixes scripts: Cyrillic and Greek look-alikes cannot spell a domain ending the masker never reads', function (string $value) {
    expect(DisplayNameRule::refusal($value))->not->toBeNull()->and(DisplayNameRule::accepts($value))->toBeFalse();
})->with([
    'Cyrillic com after a Latin name' => ["Ali. \u{0441}\u{043E}\u{043C}"],
    'Greek org after a Latin name' => ["Ali. \u{03BF}\u{03C1}\u{03B3}"],
    'Cyrillic ending after a hyphen' => ["Ali-\u{0441}\u{043E}\u{043C}"],
    'Latin and Cyrillic words' => ['Anna Мария'],
    'Latin and Greek words' => ['Anna Ελένη'],
    'Latin and Han words' => ['Wang 王'],
    'Latin name with a Devanagari mark' => ["Ali\u{0903}"],
    'Latin name with an Arabic extended mark' => ["Ali\u{08CF}"],
    'Latin name with a Han-script half mark' => ["Ali\u{302D}"],
    'Arabic and Hebrew words' => ['أميرة דוד'],
    'Cyrillic and Greek words' => ['Мария Ελένη'],
]);

it('keeps Han, Hiragana and Katakana together as one script, and admits one script per name otherwise', function (string $value) {
    expect(DisplayNameRule::accepts($value))->toBeTrue();
})->with([
    'Han and Hiragana' => ['田中 たろう'],
    'Han and Katakana' => ['田中 タロウ'],
    'Hiragana and Katakana' => ['たろう タロウ'],
    'Han, Hiragana and Katakana in one word' => ['田中たろうタロウ'],
    'Latin with a mark that does not compose' => ["Sab\u{0301}rina Khan"],
    'Cyrillic only' => ['Мария Иванова'],
]);

it('folds an honest input to NFKC and leaves anything else to the refusal', function () {
    expect(DisplayNameRule::normalise("  R\u{0133}k  "))->toBe('Rijk')
        ->and(DisplayNameRule::normalise("\u{FF33}ara"))->toBe('Sara')
        ->and(DisplayNameRule::normalise("  Sara Khan\n"))->toBe('Sara Khan')
        ->and(DisplayNameRule::normalise("Sara\xC3\x28"))->toBe("Sara\xC3\x28");

    // The folded form is judged; the raw form is not NFKC and is refused as it stands (a letter and a
    // combining accent that compose into one letter is the same: the Form Request folds it first).
    expect(DisplayNameRule::normalise("Jose\u{0301} Mu\u{0308}ller"))->toBe("Jos\u{00E9} M\u{00FC}ller")
        ->and(DisplayNameRule::accepts("Jos\u{00E9} M\u{00FC}ller"))->toBeTrue()
        ->and(DisplayNameRule::refusal("Jose\u{0301}"))->not->toBeNull();

    expect(DisplayNameRule::accepts(DisplayNameRule::normalise("R\u{0133}k")))->toBeTrue()
        ->and(DisplayNameRule::refusal("R\u{0133}k"))->not->toBeNull();

    // A trailing line break is trimmed on the way in; the untrimmed value is refused as it stands.
    expect(DisplayNameRule::accepts("Sara\n"))->toBeFalse()
        ->and(DisplayNameRule::accepts(DisplayNameRule::normalise("Sara\n")))->toBeTrue();
});

// R195 review round 1, note 6: a mobile number is ten or eleven digits, and in Han numerals (which carry a
// numeric value) or Sino-Korean syllables (which do not, so they are listed) it fits in 30 code points.
// Real names with a few numeral characters stay admitted; seven or more are refused.
it('admits up to six numeral characters, as real Han and Korean names have them, and refuses seven', function () {
    foreach (['王一二', '李三四五六', '零五零一二三', '공일공일이삼', '이일오', '김이삼 박오육'] as $name) {
        expect(DisplayNameRule::accepts($name))->toBeTrue(sprintf('"%s" has six numeral characters or fewer', $name));
    }

    foreach (['零五零一二三四', '공일공일이삼사', '령일령일이삼사', '이일오 사육칠팔'] as $name) {
        expect(DisplayNameRule::accepts($name))->toBeFalse(sprintf('"%s" has seven numeral characters', $name));
    }
});

// The masker reads these modifier letters as word characters, so "mysite" + U+02BC + "com" is invisible to it
// unless the rule folds them to the apostrophe and hyphen they stand for. Every admitted modifier letter that is
// not a Han or Kana iteration mark must be in the fold table, so a later addition to MODIFIER cannot slip past.
it('folds, for the masker, every admitted modifier letter that is not a Han or Kana iteration mark', function () {
    $folds = (new ReflectionClassConstant(DisplayNameRule::class, 'MASKER_FOLDS'))->getValue();
    $modifier = '/^'.(new ReflectionClassConstant(DisplayNameRule::class, 'MODIFIER'))->getValue().'$/u';
    $unfolded = [];
    $seen = 0;

    foreach (dnrCodePoints() as $c => $char) {
        if (preg_match($modifier, $char) !== 1) {
            continue;
        }

        // The halfwidth forms (U+FF70, U+FF9E, U+FF9F) match MODIFIER but are not NFKC, so the rule refuses
        // them before the masker is asked: only a code point that can reach the masker needs a fold.
        if (! Normalizer::isNormalized($char, Normalizer::FORM_KC)) {
            continue;
        }

        $seen++;
        $script = IntlChar::getPropertyValueName(IntlChar::PROPERTY_SCRIPT, IntlChar::getIntPropertyValue($c, IntlChar::PROPERTY_SCRIPT), IntlChar::LONG_PROPERTY_NAME);

        if (in_array($script, ['Han', 'Hiragana', 'Katakana'], true)) {
            continue;
        }

        if (! array_key_exists($char, $folds)) {
            $unfolded[] = sprintf('U+%04X', $c);
        }
    }

    expect($unfolded)->toBe([])->and($seen)->toBeGreaterThan(4);
});

it('counts every code point, marks and joiners included, toward the 2 to 30 length', function () {
    $marks = "\u{0301}\u{0302}\u{0303}\u{0304}\u{0305}";

    expect(mb_strlen(str_repeat('a', 26).$marks))->toBe(31)
        ->and(mb_strlen("Sa{$marks}ra"))->toBe(9);

    expect(DisplayNameRule::failure('Al'))->toBeNull()
        ->and(DisplayNameRule::failure(str_repeat('a', 30)))->toBeNull()
        ->and(DisplayNameRule::failure(str_repeat('a', 31)))->toBe(DisplayNameRule::LENGTH_MESSAGE)
        ->and(DisplayNameRule::failure(str_repeat('a', 26).$marks))->toBe(DisplayNameRule::LENGTH_MESSAGE)
        ->and(DisplayNameRule::failure('A'))->toBe(DisplayNameRule::LENGTH_MESSAGE)
        ->and(DisplayNameRule::failure(DisplayNameRule::normalise("A\u{0301}")))->toBe(DisplayNameRule::LENGTH_MESSAGE)
        ->and(DisplayNameRule::failure("b\u{0301}"))->toBe(DisplayNameRule::CHARACTERS_MESSAGE)
        ->and(DisplayNameRule::failure(''))->toBe(DisplayNameRule::LENGTH_MESSAGE);

    // Thirty letters and a mark that does not compose are 31 code points, though 30 graphemes.
    expect(DisplayNameRule::failure(str_repeat('a', 29)."b\u{0301}"))->toBe(DisplayNameRule::LENGTH_MESSAGE);
});

it('reports a character failure, a contact-detail failure and a length failure with their own messages', function () {
    expect(DisplayNameRule::failure('Sara 2'))->toBe(DisplayNameRule::CHARACTERS_MESSAGE)
        ->and(DisplayNameRule::failure("Sara\u{200B}Khan"))->toBe(DisplayNameRule::CHARACTERS_MESSAGE)
        ->and(DisplayNameRule::failure('A'))->toBe(DisplayNameRule::LENGTH_MESSAGE);

    // A name the structural rules admit but the masker reads as contact details gets the contact message.
    $greedy = new class extends MessageMasker
    {
        public function mask(string $body, bool $spelledOut = true): MaskedMessage
        {
            return new MaskedMessage($body, str_contains($body, 'Sara'));
        }
    };
    app()->instance(MessageMasker::class, $greedy);

    expect(DisplayNameRule::failure('Sara Khan'))->toBe(DisplayNameRule::CONTACT_MESSAGE);
});

it('plugs into the Laravel validator and skips a blank or non-string value', function () {
    $rules = ['display_name' => ['nullable', 'string', new DisplayNameRule]];

    expect(validator(['display_name' => 'Sara Khan'], $rules)->passes())->toBeTrue()
        ->and(validator(['display_name' => ''], $rules)->passes())->toBeTrue()
        ->and(validator(['display_name' => null], $rules)->passes())->toBeTrue()
        ->and(validator(['display_name' => 'Sara 2'], $rules)->errors()->first('display_name'))->toBe(DisplayNameRule::CHARACTERS_MESSAGE)
        ->and(validator(['display_name' => 'A'], $rules)->errors()->first('display_name'))->toBe(DisplayNameRule::LENGTH_MESSAGE);

    $direct = ['display_name' => [new DisplayNameRule]];

    expect(validator(['display_name' => 42], $direct)->passes())->toBeTrue()
        ->and(validator(['display_name' => ['Sara']], $direct)->passes())->toBeTrue();
});

it('accepts the real demo seeder names and five Arabic, Urdu and Hindi names', function (string $value) {
    expect(DisplayNameRule::refusal($value))->toBeNull()->and(DisplayNameRule::accepts($value))->toBeTrue();
})->with(function () {
    $seeded = (new ReflectionClassConstant(DemoTutorSeeder::class, 'TUTORS'))->getValue();

    $names = [];
    foreach ($seeded as $tutor) {
        $names[$tutor['name']] = [$tutor['name']];
        $names[explode(' ', $tutor['name'])[0]] = [explode(' ', $tutor['name'])[0]];
    }

    return $names + [
        'arabic Amira' => ['أميرة'],
        'arabic Muhammad pointed' => ['مُحَمَّد'],
        'arabic Abdullah bin Khalid' => ['عبد الله'],
        'urdu Fatima Zahra' => ['فاطمہ زہرا'],
        'urdu Ali Raza' => ['علی رضا'],
        'hindi Priya Sharma' => ['प्रिया शर्मा'],
        'hindi Ananya' => ['अनन्या'],
        'hindi conjunct with joiner' => ["क\u{094D}\u{200D}ष"],
        'persian Alireza' => ['علیرضا'],
        'hebrew' => ['דוד כהן'],
        'pointed hebrew' => ['שָׁלוֹם'],
        'thai' => ['สมชาย'],
        'tamil' => ['பிரியா'],
        'cyrillic' => ['Мария'],
        'greek' => ['Ελένη'],
        'han' => ['李明'],
        'han numeral inside a name' => ['一郎'],
        'kana with long vowel' => ['ソーラ'],
        'japanese' => ['田中 太郎'],
        'hangul' => ['김민준'],
        'latin accents' => ['José Müller'],
        'vietnamese stacked accents' => ['Nguyễn Thị'],
        'okina' => ['Keʻala'],
        'title and full stop' => ['Dr. Sara Khan'],
        'short title' => ['Ms. Li'],
        'short title and short name' => ['Mr. Ng'],
        'title before a two-letter name that is also a country code' => ['Dr. Al Khan'],
        'iteration mark in a Japanese name' => ['佐々木'],
        'okina leading a name' => ['ʻAlika'],
        'apostrophe-like letter inside a name' => ['Dʼsouza'],
        'middle initial' => ['Mohammed A. Khan'],
        'hyphen and apostrophe' => ["Mary-Jane O'Neil"],
        'typographic apostrophe' => ['O’Neil'],
        'trailing full stop' => ['Sara K.'],
        'two letters' => ['Al'],
        'thirty characters' => ['Abdulrahman Mohammed Al Sayed'],
    ];
});

// What the rule does not decide (listed in its docblock): a short word before a short ending reads to the
// masker as a name, so the rule admits it. The admin is the backstop at approval. Pinned so that a change
// here is a decision, not an accident.
it('admits by design a short word before a short ending, leaving it to the admin', function (string $value) {
    expect(DisplayNameRule::accepts($value))->toBeTrue();
})->with([
    'Ali. co' => ['Ali. co'],
    'Bob. io' => ['Bob. io'],
    'Wa. me' => ['Wa. me'],
]);

// Known false refusals, all in the safe direction (listed in the rule docblock): a legitimate name that
// the rule turns away. A tutor who hits one gets the first-name default or types the name without it.
it('knowingly refuses a few legitimate spellings rather than admit what the masker strips', function (string $value) {
    expect(DisplayNameRule::accepts($value))->toBeFalse();
})->with([
    'Persian zero-width non-joiner (the masker strips it)' => ["می\u{200C}خواهم"],
    'middle dot' => ["Sara\u{00B7}Khan"],
    'Hebrew geresh' => ["ג\u{05F3}ק"],
    'full stop inside a word' => ['Bo.Li'],
    'Armenian' => ['Արամ'],
    'title of four letters before a full stop and space' => ['Prof. Ali'],
    'two or more marks then exactly two letters closing the name (the masker reads the marks as a dot and the two letters as a country code: Sara with two marks on the a, but not Sarah)' => ["Sa\u{0301}\u{0302}ra"],
    'hyphenated name the masker reads as an address' => ['Mary-Jo'],
]);

it('keeps the fail-closed behaviour when the masker cannot decide', function () {
    $broken = new class extends MessageMasker
    {
        public function mask(string $text, bool $spelledOut = true): never
        {
            throw new MessageMaskingFailedException('forced');
        }
    };
    app()->instance(MessageMasker::class, $broken);

    expect(DisplayNameRule::accepts('Sara Khan'))->toBeFalse()
        ->and(DisplayNameRule::failure('Sara Khan'))->toBe(DisplayNameRule::CONTACT_MESSAGE);
});
