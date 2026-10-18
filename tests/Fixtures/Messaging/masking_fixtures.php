<?php

use App\Support\Messaging\MessageMasker;

// The fixture table of MessageMaskerTest (R134: the table is the specification), moved here unchanged in
// cycle 19a (R230) so the regression corpus can reuse every row. Each row is [input, expected stored text].
return [
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
    // R157(c): EMAIL's domain labels dropped '-' from their character class (see MessageMasker's class
    // docblock and BARE_DOMAIN_LABEL). A real hyphenated business domain must still mask whole: the dash
    // is now consumed by LABEL_GAP as an ordinary junk separator between "my" and "site" instead of sitting
    // inside one greedy label, but the matched span -- and this fixture's expected output -- is unchanged.
    'hyphenated domain still masks whole (R157(c))' => ['admin@my-site.com', MessageMasker::PLACEHOLDER],
    // The local part's own class (unaffected by R157(c)) still keeps '-'.
    'dash in the local part is unaffected by R157(c)' => ['sara-k@gmail.com', MessageMasker::PLACEHOLDER],
    // AT_GAP ('[^\p{L}\p{Nd}@]*+') still includes '-', so a dash immediately after '@' is absorbed
    // there before BARE_DOMAIN_LABEL ever starts matching -- confirmed empirically, not a leak.
    'leading dash after @ still masks whole (R157(c))' => ['sara@-name.com', MessageMasker::PLACEHOLDER],
    // Disclosed cosmetic-only behaviour change (CYCLE-LOG DEVIATION, ADR-019 amendment): a bare
    // trailing dash right after a fully-formed domain is no longer absorbed into the match, since
    // the domain label class dropped '-'. Pinned as a fixture per ADR-019's convention.
    'trailing dash after domain is left outside the match (R157(c) disclosed behaviour change)' => ['sara@name.com-', MessageMasker::PLACEHOLDER.'-'],
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

    // Round 3 (R143 skeleton rewrite): doubled/bracketed/parenthesized/braced dots, ellipsis, doubled bullets
    'doubled dot' => ['visit mysite..com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'bracketed dot' => ['visit mysite[.]com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'parenthesized dot' => ['visit mysite(.)com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'braced dot' => ['visit mysite{.}com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'ellipsis for the dot' => ['visit mysite…com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'doubled bullets for the dot' => ['visit mysite••com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'bullet after a real dot' => ['visit mysite.•com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    // A label is not distinguished from the spelled-out separator words "at"/"dot" — deliberately, or a
    // real bare domain built the same way would leak (accepted over-masking, R143 skeleton design).
    'spelled-out at and dot in brackets is accepted over-masking (R143)' => ['sara (at) gmail (dot) com', MessageMasker::PLACEHOLDER],
    'a literal "dot" domain label is not mistaken for the spelled-out separator' => ['visit mysite.dot.com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],

    // Round 3: a symbol directly beside the at sign, on either side, or wrapping the whole local part
    'exclaim between local part and at' => ['sara!@gmail.com', MessageMasker::PLACEHOLDER],
    'hash between local part and at' => ['sara#@gmail.com', MessageMasker::PLACEHOLDER],
    // The opening quote sits before the local part starts, so it is not part of the match and survives —
    // the strong signal is the `@`, and leaving a bare punctuation mark visible is not over- or under-masking.
    'quoted local part' => ['"sara"@gmail.com', '"'.MessageMasker::PLACEHOLDER],
    'star between at and provider' => ['sara@*gmail.com', MessageMasker::PLACEHOLDER],
    'exclaim between at and provider' => ['sara@!gmail.com', MessageMasker::PLACEHOLDER],
    'doubled at sign' => ['sara@@mysite.com', MessageMasker::PLACEHOLDER],
    // EMAIL never required a recognised TLD ending — an unlisted ending such as ".art" only matters to the
    // bare-domain patterns, which do not apply once an `@` has anchored the match.
    'doubled at sign, unlisted ending still matches via the at sign' => ['sara@@mysite.art', MessageMasker::PLACEHOLDER],

    // Round 3: comma, arabic comma, fullwidth comma, slash and dash as a non-word-ending domain separator
    'comma and space for the dot' => ['visit mysite, com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'arabic comma for the dot' => ['visit mysite، com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'fullwidth comma for the dot' => ['visit mysite，com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'slash with spaces for the dot' => ['visit mysite / com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'dash with spaces for the dot' => ['visit mysite - com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],

    // Round 3: a two-letter country code after an exotic tight separator
    'bullet-tight country code' => ['visit mysite•ae now', 'visit '.MessageMasker::PLACEHOLDER.' now'],

    // Round 3: single-digit enclosed number symbols not already folded by NFKC
    'dingbat negative circled digits' => ['❶❷❸❹❺❻❼', MessageMasker::PLACEHOLDER],
    'dingbat negative circled sans-serif digits' => ['➊➋➌➍➎➏➐', MessageMasker::PLACEHOLDER],
    'double circled digits' => ['⓵⓶⓷⓸⓹⓺⓻', MessageMasker::PLACEHOLDER],
    // NFKC (applied ahead of the digit fold, unrelated to this rewrite) already decomposes "½" into
    // "1" + U+2044 FRACTION SLASH + "2" on its own, so this is not exercising foldEnclosedDigits at all.
    'a half is decomposed by NFKC before the digit fold ever runs' => ['about ½ an hour', "about 1\u{2044}2 an hour"],

    // Round 3: a separator unit is now one grapheme, not one code point, so a handful of multi-codepoint
    // emoji between digit groups no longer defeats the six-unit budget
    'two family emoji between digit groups' => ["050\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}123\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}4567", MessageMasker::PLACEHOLDER],
    'four skin-tone thumbs between digit groups' => ["050\u{1F44D}\u{1F3FB}\u{1F44D}\u{1F3FC}\u{1F44D}\u{1F3FD}\u{1F44D}\u{1F3FE}123\u{1F44D}\u{1F3FB}\u{1F44D}\u{1F3FC}\u{1F44D}\u{1F3FD}\u{1F44D}\u{1F3FE}4567", MessageMasker::PLACEHOLDER],
    'four flags between digit groups' => ["050\u{1F1E6}\u{1F1EA}\u{1F1FA}\u{1F1F8}\u{1F1EC}\u{1F1E7}\u{1F1EB}\u{1F1F7}123\u{1F1E6}\u{1F1EA}\u{1F1FA}\u{1F1F8}\u{1F1EC}\u{1F1E7}\u{1F1EB}\u{1F1F7}4567", MessageMasker::PLACEHOLDER],

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

    // R144 (cycle 08 r3, round 4 of the 8b review, Finding 1): every junk gap used to cap the number of
    // junk characters it would match (LOOSE_DOT_JUNK {1,3}, LABEL_GAP/TIGHT_JUNK {1,6}) — once real
    // punctuation exceeded the cap the pattern failed to match *at all*, so the address or domain went
    // through fully unmasked. Every gap is now unbounded: repeating a separator already present in a gap
    // can only ever widen what gets masked, never defeat the match. One case per pattern that has its own
    // gap shape (an anchored email, a bare domain with a non-word ending, one with a word-like ending, and
    // a country code), each at the exact repro length (7), and padded much further (50, 500) to prove the
    // fix is not itself capped at some other number, plus one run mixing several junk characters together
    // rather than repeating just one.
    'R144 repro: seven dots defeated the old cap and went through unmasked' => ['sara@name.......com', MessageMasker::PLACEHOLDER],
    'R144 email, 50 dots' => ['sara@name'.str_repeat('.', 50).'com', MessageMasker::PLACEHOLDER],
    'R144 email, 500 dots' => ['sara@name'.str_repeat('.', 500).'com', MessageMasker::PLACEHOLDER],
    'R144 email, mixed junk run' => ['sara@name'.str_repeat(' .,-  ', 5).'com', MessageMasker::PLACEHOLDER],
    'R144 non-word-ending bare domain, 7 dots' => ['visit mysite.......com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'R144 non-word-ending bare domain, 50 dots' => ['visit mysite'.str_repeat('.', 50).'com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'R144 non-word-ending bare domain, 500 dots' => ['visit mysite'.str_repeat('.', 500).'com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'R144 non-word-ending bare domain, mixed junk run' => ['visit mysite'.str_repeat(' .,-  ', 5).'com now', 'visit '.MessageMasker::PLACEHOLDER.' now'],
    'R144 word-ending bare domain, 7 dots' => ['mysite.......online', MessageMasker::PLACEHOLDER],
    'R144 word-ending bare domain, 50 dots' => ['mysite'.str_repeat('.', 50).'online', MessageMasker::PLACEHOLDER],
    'R144 word-ending bare domain, 500 dots' => ['mysite'.str_repeat('.', 500).'online', MessageMasker::PLACEHOLDER],
    'R144 word-ending bare domain, mixed junk run (tight, no whitespace)' => ['mysite'.str_repeat('.,-', 5).'online', MessageMasker::PLACEHOLDER],
    'R144 country-code domain, 7 dots' => ['mysite.......ae', MessageMasker::PLACEHOLDER],
    'R144 country-code domain, 50 dots' => ['mysite'.str_repeat('.', 50).'ae', MessageMasker::PLACEHOLDER],
    'R144 country-code domain, 500 dots' => ['mysite'.str_repeat('.', 500).'ae', MessageMasker::PLACEHOLDER],
    'R144 country-code domain, mixed junk run (tight, no whitespace)' => ['mysite'.str_repeat('.,-', 5).'ae', MessageMasker::PLACEHOLDER],

    // R149(b): NAMED_DOMAIN_LOOSE/_TIGHT/COUNTRY_DOMAIN's outer group is now capped at
    // MAX_BARE_DOMAIN_LABELS (16) iterations (see MessageMasker's class docblock and the
    // MAX_BARE_DOMAIN_LABELS constant). A domain with more labels than the cap allows is not left fully
    // unmasked: the pattern is anchored at the ending, not the first label, so it still matches starting
    // from a later label — here 19 single-letter labels ("a" through "s") before ".com", two more than
    // the 17-label window (1 + 16 additional) the pattern can cover in one match, so the leftmost
    // starting position that fits is the third label ("c"): "a." and "b." are left unmasked, "c" through
    // "s" and "com" are one placeholder. Confirmed against the live pattern, not just reasoned about (see
    // CYCLE-LOG VERIFICATION).
    'R149(b) more bare-domain labels than the cap: masks the ending and the labels the cap allows, not the whole chain, and never passes through unmasked' => [
        'a.b.c.d.e.f.g.h.i.j.k.l.m.n.o.p.q.r.s.com',
        'a.b.'.MessageMasker::PLACEHOLDER,
    ],

    // Known limits (ADR-019)
    'colon-separated number' => ['050:123:4567', '050:123:4567'],
    'lookalike letters for digits' => ['O5O l234567', 'O5O l234567'],
    'a spaced word-like ending is a known limit (R143)' => ['contact me at mysite. online please', 'contact me at mysite. online please'],
    // Pure whitespace alone, with no punctuation at all, is deliberately not a valid bare-domain
    // separator (R143) — otherwise ordinary line-wrapped sentences would bridge into a false positive.
    'a bare newline with no punctuation is a known limit (R143)' => ["visit mysite\ncom now", "visit mysite\ncom now"],

    // R231: spelled-out shapes (`SpelledOutPrePass`)
    'R231 spelled-out digits (formerly a known limit)' => ['zero five zero one two three four five six seven', MessageMasker::PLACEHOLDER],
    'R231 spelled-out address (formerly a known limit)' => ['sara at gmail dot com', MessageMasker::PLACEHOLDER],
    'R231 spelled-out address inside a sentence' => ['mail sara at gmail dot com now', 'mail '.MessageMasker::PLACEHOLDER.' now'],
    'R231 round brackets round at and dot' => ['write to sara (at) example (dot) com please', 'write to '.MessageMasker::PLACEHOLDER.' please'],
    'R231 square brackets round at and dot' => ['sara [at] example [dot] com', MessageMasker::PLACEHOLDER],
    'R231 curly brackets round at' => ['sara {at} example.com', MessageMasker::PLACEHOLDER],
    'R231 spaces round the spelled dot' => ['sara at gmail . com', MessageMasker::PLACEHOLDER],
    'R231 hyphenated domain label' => ['sara at my-site dot com ok', MessageMasker::PLACEHOLDER.' ok'],
    'R231 two-part country ending' => ['sara.k at gmail dot co dot uk', MessageMasker::PLACEHOLDER],
    'R231 bare mail provider after at' => ['mail me at sara at gmail', 'mail me at '.MessageMasker::PLACEHOLDER],
    'R231 at before a domain with a path' => ['sara at example.com/path', MessageMasker::PLACEHOLDER],
    'R231 upper-case at and dot' => ['Sara AT Gmail DOT Com', MessageMasker::PLACEHOLDER],
    'R231 spelled-out www address' => ['see www dot example dot com now', 'see '.MessageMasker::PLACEHOLDER.' now'],
    'R231 digit words with a lead-in' => ['call zero five zero one two three four five six please', 'call '.MessageMasker::PLACEHOLDER.' please'],
    'R231 digit words with commas' => ['call zero, five, zero, one, two, three, four', 'call '.MessageMasker::PLACEHOLDER],
    'R231 digit words with oh and double' => ['double five oh one two three four', MessageMasker::PLACEHOLDER],
    'R231 triple digit word' => ['triple five one two three four', MessageMasker::PLACEHOLDER],
    'R231 digit words in capitals' => ['ZERO FIVE ZERO ONE TWO THREE FOUR', MessageMasker::PLACEHOLDER],
    'R231 messenger with an at handle' => ['whatsapp @sara_k thanks', MessageMasker::PLACEHOLDER.' thanks'],
    'R231 messenger with digits' => ['telegram 0501234 thanks', MessageMasker::PLACEHOLDER.' thanks'],
    'R231 messenger with words before the handle' => ['my whatsapp is @sara_k ok', 'my '.MessageMasker::PLACEHOLDER.' ok'],
    'R231 messenger with a plus and digits' => ['+whatsapp 0501234', '+'.MessageMasker::PLACEHOLDER],
    'R231 messenger name in capitals' => ['WhatsApp @sara_k', MessageMasker::PLACEHOLDER],
    'R231 instagram handle' => ['insta: @sara_k', MessageMasker::PLACEHOLDER],
    'R231 snapchat and digits' => ['add me on snapchat 5550142 later', 'add me on '.MessageMasker::PLACEHOLDER.' later'],
    'R231 hyphenated messenger prefix' => ['tutor-whatsapp @sara_k', MessageMasker::PLACEHOLDER],

    // Negatives (R231): ordinary prose that shares a word with a spelled-out shape and must be left alone
    'R231 prose "at school" then a sentence' => ['meet me at school. Then we go', 'meet me at school. Then we go'],
    'R231 prose "at home" with no space after the dot' => ['see you at home.Thanks', 'see you at home.Thanks'],
    'R231 polka dot' => ['polka dot is cute', 'polka dot is cute'],
    'R231 the dot com bubble' => ['the dot com bubble', 'the dot com bubble'],
    'R231 a red dot on the map' => ['a red dot on the map', 'a red dot on the map'],
    'R231 dotted is not dot' => ['a dotted line here', 'a dotted line here'],
    'R231 chat is not at' => ['that chat is nice', 'that chat is nice'],
    'R231 messenger with a short number' => ['whatsapp 123', 'whatsapp 123'],
    'R231 messenger with a time' => ['discord at 5pm', 'discord at 5pm'],
    'R231 four digit words only' => ['one two three four', 'one two three four'],
    'R231 digit words in prose' => ['one of the two apps, three days', 'one of the two apps, three days'],
    'R231 at home then a sentence' => ['I am at home. Also fine', 'I am at home. Also fine'],
    'R231 messenger name inside a word' => ['a signalman at work', 'a signalman at work'],

    // Documented over-masking (R231): the chain already hid the domain; the rewrite makes the word before "at" part of the address
    'R231 over-mask: at a domain with a country code' => ['we are at zoom.us', 'we '.MessageMasker::PLACEHOLDER],
    'R231 over-mask: a time written with a dot' => ['meet me at 5.pm', 'meet '.MessageMasker::PLACEHOLDER],
];
