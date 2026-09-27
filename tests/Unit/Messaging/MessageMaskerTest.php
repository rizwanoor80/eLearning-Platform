<?php

use App\Support\Messaging\MessageMasker;

// The fixture table is the specification (R134): [input, expected stored text].
dataset('masking fixtures', [
    // Email addresses
    'plain email' => ['write to sara@example.com please', 'write to '.MessageMasker::PLACEHOLDER.' please'],
    'email with plus tag and subdomain' => ['sara.k+lessons@mail.school.co.uk', MessageMasker::PLACEHOLDER],
    'email with spaces around the dot' => ['sara@gmail . com', MessageMasker::PLACEHOLDER],
    'email with spaces around at and dot' => ['sara @ gmail . co . uk', MessageMasker::PLACEHOLDER],
    'email with spaces around the at sign' => ['sara @ example.com', MessageMasker::PLACEHOLDER],
    'fullwidth at sign' => ['sara＠example.com', MessageMasker::PLACEHOLDER],
    'uppercase email' => ['SARA@EXAMPLE.COM', MessageMasker::PLACEHOLDER],
    'arabic letters in the local part' => ['سارة@example.com', MessageMasker::PLACEHOLDER],

    // URLs and bare domains
    'https url' => ['see https://example.com/path?a=1 now', 'see '.MessageMasker::PLACEHOLDER.' now'],
    'http url with port' => ['http://example.com:8080/x', MessageMasker::PLACEHOLDER],
    'other scheme' => ['ftp://files.example.net/a.zip', MessageMasker::PLACEHOLDER],
    'www without scheme' => ['go to www.example.com/lessons', 'go to '.MessageMasker::PLACEHOLDER],
    'bare domain' => ['my site is name.com', 'my site is '.MessageMasker::PLACEHOLDER],
    'bare domain with path' => ['name.com/tutor', MessageMasker::PLACEHOLDER],
    'short link' => ['wa.me/971501234567', MessageMasker::PLACEHOLDER],
    'bare domain in capitals' => ['NAME.COM', MessageMasker::PLACEHOLDER],
    'subdomain' => ['tutor.example.org', MessageMasker::PLACEHOLDER],
    'fullwidth dot in a domain' => ['name．com', MessageMasker::PLACEHOLDER],

    // Phone numbers
    'plain digits' => ['call 0501234567', 'call '.MessageMasker::PLACEHOLDER],
    'international with plus' => ['+971 50 123 4567', MessageMasker::PLACEHOLDER],
    'dashes' => ['050-123-4567', MessageMasker::PLACEHOLDER],
    'dots' => ['050.123.4567', MessageMasker::PLACEHOLDER],
    'brackets' => ['(050) 123 4567', MessageMasker::PLACEHOLDER],
    'mixed separators' => ['+44 (0) 7700-900.123', MessageMasker::PLACEHOLDER],
    'seven digits is the smallest run' => ['1234567', MessageMasker::PLACEHOLDER],
    'arabic-indic digits' => ['٠٥٠١٢٣٤٥٦٧', MessageMasker::PLACEHOLDER],
    'extended arabic-indic (persian) digits' => ['۰۵۰۱۲۳۴۵۶۷', MessageMasker::PLACEHOLDER],
    'arabic-indic digits with spaces' => ['٠٥٠ ١٢٣ ٤٥٦٧', MessageMasker::PLACEHOLDER],
    'fullwidth digits' => ['０５０１２３４５６７', MessageMasker::PLACEHOLDER],
    'circled digits' => ['⑤⓪①②③④⑤⑥', MessageMasker::PLACEHOLDER],
    'digits split by single spaces' => ['0 5 0 1 2 3 4 5 6 7', MessageMasker::PLACEHOLDER],
    'two numbers in one message' => ['0501234567 or 0509876543', MessageMasker::PLACEHOLDER.' or '.MessageMasker::PLACEHOLDER],
    'newline between digit groups' => ["0501\n234567", MessageMasker::PLACEHOLDER],
    'carriage return and newline' => ["050\r\n123\r\n4567", MessageMasker::PLACEHOLDER],
    'keycap digits' => ["0\u{FE0F}\u{20E3}5\u{FE0F}\u{20E3}0\u{FE0F}\u{20E3}1\u{FE0F}\u{20E3}2\u{FE0F}\u{20E3}3\u{FE0F}\u{20E3}4\u{FE0F}\u{20E3}", MessageMasker::PLACEHOLDER],
    'commas between digit groups' => ['050,123,4567', MessageMasker::PLACEHOLDER],
    'arabic decimal and thousands separators' => ["٠٥٠\u{066B}١٢٣\u{066C}٤٥٦٧", MessageMasker::PLACEHOLDER],
    'digits separated by slashes and underscores' => ['050/123_4567', MessageMasker::PLACEHOLDER],
    'zero-width space between digits' => ["050\u{200B}123\u{200B}4567", MessageMasker::PLACEHOLDER],
    'zero-width joiner and word joiner' => ["050\u{200D}123\u{2060}4567", MessageMasker::PLACEHOLDER],
    'soft hyphen and bom' => ["\u{FEFF}050\u{00AD}1234567", MessageMasker::PLACEHOLDER],
    'slashes' => ['050/123/4567', MessageMasker::PLACEHOLDER],
    'five spaces between groups' => ['050     123     4567', MessageMasker::PLACEHOLDER],
    'a run of tabs' => ['050					123					4567', MessageMasker::PLACEHOLDER],
    'doubled dashes with spaces' => ['050 - - 123 - - 4567', MessageMasker::PLACEHOLDER],
    'many blank lines' => ['050





123



4567', MessageMasker::PLACEHOLDER],
    'stars' => ['050*123*4567', MessageMasker::PLACEHOLDER],
    'tag characters in an address' => ['sara@gmail󠀠.com', MessageMasker::PLACEHOLDER],
    'blank braille in an address' => ['sara@gmail⠀.com', MessageMasker::PLACEHOLDER],
    'variation selector in a bare domain' => ['example️.com', MessageMasker::PLACEHOLDER],
    'combining joiner around the dot' => ['sara@gmail͏.͏com', MessageMasker::PLACEHOLDER],
    'eight tag characters between digit groups' => ['050'.str_repeat('󠀠', 8).'123'.str_repeat('󠀠', 8).'4567', MessageMasker::PLACEHOLDER],
    'seven blank braille between digit groups' => ['050'.str_repeat('⠀', 7).'123'.str_repeat('⠀', 7).'4567', MessageMasker::PLACEHOLDER],
    'arabic tatweel between arabic-indic digits' => ['٠٥٠ـ١٢٣ـ٤٥٦٧', MessageMasker::PLACEHOLDER],
    'tatweel between digits' => ['050ـ123ـ4567', MessageMasker::PLACEHOLDER],
    'katakana prolonged sound mark between digits' => ['050ー123ー4567', MessageMasker::PLACEHOLDER],
    'bullet for the dot' => ['sara@gmail•com', MessageMasker::PLACEHOLDER],
    'dot operator for the dot' => ['sara@gmail⋅com', MessageMasker::PLACEHOLDER],
    'comma for the dot' => ['sara@gmail,com', MessageMasker::PLACEHOLDER],
    'dash for the dot' => ['sara@gmail-com', MessageMasker::PLACEHOLDER],
    'mail provider with a space for the dot' => ['sara@gmail com', MessageMasker::PLACEHOLDER.' com'],
    'mail provider with no dot' => ['sara@hotmail', MessageMasker::PLACEHOLDER],
    'mail provider in capitals with spaces' => ['sara  @  GMAIL  com', MessageMasker::PLACEHOLDER.'  com'],
    'bare domain with a newer ending' => ['mysite.email', MessageMasker::PLACEHOLDER],
    'bare domain with school ending' => ['sara.school', MessageMasker::PLACEHOLDER],
    'bare domain with a bullet' => ['example•com', MessageMasker::PLACEHOLDER],
    'pinned over-masking: at-phrase with a time' => ['see you @ 5:30', 'see '.MessageMasker::PLACEHOLDER],
    'pinned over-masking: at-phrase with a comma' => ['see you @ school, then', 'see '.MessageMasker::PLACEHOLDER],
    'hangul filler between digits' => ['050ㅤ123ㅤ4567', MessageMasker::PLACEHOLDER],
    'half-width hangul filler' => ['050ﾠ123ﾠ4567', MessageMasker::PLACEHOLDER],
    'khmer and jamo fillers' => ['050ᅟ123ᅠ4567឴', MessageMasker::PLACEHOLDER],
    'email with double spaces around at and dot' => ['sara  @ gmail. com', MessageMasker::PLACEHOLDER],
    'email with tabs and blank line' => ['sara	@

gmail.com', MessageMasker::PLACEHOLDER],
    'email with ideographic full stop' => ['sara@gmail。com', MessageMasker::PLACEHOLDER],
    'email with middle dot' => ['sara@gmail·com', MessageMasker::PLACEHOLDER],
    'email with arabic full stop' => ['sara@gmail۔com', MessageMasker::PLACEHOLDER],
    'bare domain with ideographic full stop' => ['gmail。com', MessageMasker::PLACEHOLDER],
    'pipes' => ['050|123|4567', MessageMasker::PLACEHOLDER],
    'hashes' => ['050#123#4567', MessageMasker::PLACEHOLDER],
    'emoji between groups' => ['050😀123😀4567', MessageMasker::PLACEHOLDER],
    'no-break space' => ['050 123 4567', MessageMasker::PLACEHOLDER],
    'line separator' => ['050 123 4567', MessageMasker::PLACEHOLDER],
    'brackets and plus' => ['+971 (50) 123-4567', MessageMasker::PLACEHOLDER],
    'spaced bare domain' => ['visit mysite . com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'spaced www domain' => ['www . mysite . com', MessageMasker::PLACEHOLDER],
    'space before the dot only' => ['mysite .com', MessageMasker::PLACEHOLDER],
    'percent-encoded at sign' => ['sara%40gmail.com', 'sara%'.MessageMasker::PLACEHOLDER],

    // Left alone
    'ordinary sentence' => ['See you on Tuesday at 5 pm, bring chapter 3.', 'See you on Tuesday at 5 pm, bring chapter 3.'],
    'six digits' => ['ref 123456', 'ref 123456'],
    'a price' => ['it costs AED 150', 'it costs AED 150'],
    'a time range' => ['from 16:00 to 18:00', 'from 16:00 to 18:00'],
    'a time range with a dash' => ['lesson 16:00-17:00 on Monday', 'lesson 16:00-17:00 on Monday'],
    'short numbers on separate lines' => ['chapter 3

question 12
45', 'chapter 3

question 12
45'],
    'a sentence ending before a common ending word' => ['Ok. Online lessons are fine. Live is better.', 'Ok. Online lessons are fine. Live is better.'],
    'a sentence ending before a country-code-like word' => ['see you. Me too', 'see you. Me too'],
    'abbreviation with dots' => ['e.g. fractions, i.e. the basics', 'e.g. fractions, i.e. the basics'],
    'a decimal' => ['I scored 3.5 out of 5', 'I scored 3.5 out of 5'],
    'a long word with a dot but no ending' => ['thanks.Really', 'thanks.Really'],
    'arabic text' => ['شكرا جزيلا على الدرس', 'شكرا جزيلا على الدرس'],
    'a lone at sign' => ['meet @ 5', 'meet @ 5'],

    // Known false positives: the safe side is over-masking (ADR-019)
    'a date with dashes' => ['the exam is on 27-09-2026', 'the exam is on '.MessageMasker::PLACEHOLDER],
    'a file name that ends like a country code' => ['I attached solution.py', 'I attached '.MessageMasker::PLACEHOLDER],
    'a date with slashes' => ['due 27/09/2026', 'due '.MessageMasker::PLACEHOLDER],
    'year groups in a list' => ['Year 10, 11, 12, 13', 'Year '.MessageMasker::PLACEHOLDER],
    'a large number with commas' => ['10,000,000', MessageMasker::PLACEHOLDER],
    'a pdf file name' => ['I attached notes.pdf', 'I attached notes.pdf'],

    // Known limits (ADR-019): spelled out is not caught
    'spelled-out digits' => ['zero five zero one two three four five six seven', 'zero five zero one two three four five six seven'],
    'colon-separated number' => ['050:123:4567', '050:123:4567'],
    'lookalike letters for digits' => ['O5O l234567', 'O5O l234567'],
    'at and dot in brackets' => ['sara (at) gmail (dot) com', 'sara (at) gmail (dot) com'],
    'spelled-out address' => ['sara at gmail dot com', 'sara at gmail dot com'],
]);

it('masks and leaves alone as the fixture table says', function (string $input, string $expected) {
    expect((new MessageMasker)->mask($input)->text)->toBe($expected);
})->with('masking fixtures');

it('reports whether anything was hidden', function () {
    $masker = new MessageMasker;

    expect($masker->mask('call 0501234567')->masked)->toBeTrue()
        ->and($masker->mask('see you at five')->masked)->toBeFalse()
        ->and($masker->mask("hello\u{200B} there")->masked)->toBeFalse();
});

it('never lets a masked original survive in the result', function (string $original, string $secret) {
    expect((new MessageMasker)->mask("hi {$original} bye")->text)->not->toContain($secret);
})->with([
    ['sara@example.com', 'sara'],
    ['https://example.com/x', 'example'],
    ['+971 50 123 4567', '123'],
    ['٠٥٠١٢٣٤٥٦٧', '٥٠١'],
]);

it('is idempotent: masking masked text changes nothing', function () {
    $masker = new MessageMasker;
    $once = $masker->mask('mail sara@example.com or call 050 123 4567 or see name.com')->text;

    expect($masker->mask($once)->text)->toBe($once);
});

it('scrubs invalid UTF-8 instead of failing or leaking', function () {
    $text = (new MessageMasker)->mask("call 0501234567 \xC3\x28 ok")->text;

    expect($text)->toContain(MessageMasker::PLACEHOLDER)->and($text)->not->toContain('0501234567');
});

it('handles a very long body without a regex failure', function () {
    $result = (new MessageMasker)->mask(str_repeat('1 ', 900).str_repeat('a.', 900));

    expect($result->text)->toContain(MessageMasker::PLACEHOLDER);
});
