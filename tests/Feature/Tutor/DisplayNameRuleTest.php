<?php

use App\Exceptions\MessageMaskingFailedException;
use App\Rules\DisplayNameRule;
use App\Support\Messaging\MessageMasker;
use Database\Seeders\DemoTutorSeeder;

/**
 * R192: the display-name rule is built on rules, not a list. Fixtures are every bypass the three review
 * rounds found, plus sweeps over the whole code space that fail if the rule and the masker diverge.
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

it('refuses every bypass found in the review rounds', function (string $value) {
    expect(DisplayNameRule::accepts($value))->toBeFalse();
})->with([
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
    'trailing line break' => ["Sara\n"],
    'tab' => ["Sara\tKhan"],
    'invalid UTF-8' => ["Sara\xC3\x28Khan"],
]);

it('counts marks and joiners toward the 2 to 30 length', function () {
    $marks = "\u{0301}\u{0302}\u{0303}\u{0304}\u{0305}";

    expect(mb_strlen(str_repeat('a', 26).$marks))->toBe(31)
        ->and(mb_strlen("Sa{$marks}ra"))->toBe(9);

    $rules = ['display_name' => ['bail', 'nullable', 'string', 'min:2', 'max:30', new DisplayNameRule]];

    expect(validator(['display_name' => str_repeat('a', 26)], $rules)->passes())->toBeTrue()
        ->and(validator(['display_name' => str_repeat('a', 26).$marks], $rules)->passes())->toBeFalse()
        ->and(validator(['display_name' => "A\u{0301}"], $rules)->passes())->toBeFalse();
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
    ];
});

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
]);

it('keeps the fail-closed behaviour when the masker cannot decide', function () {
    $broken = new class extends MessageMasker
    {
        public function mask(string $text): never
        {
            throw new MessageMaskingFailedException('forced');
        }
    };
    app()->instance(MessageMasker::class, $broken);

    expect(DisplayNameRule::accepts('Sara Khan'))->toBeFalse();
});
