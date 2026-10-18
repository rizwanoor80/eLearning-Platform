<?php

namespace Tests\Support\Messaging;

use App\Exceptions\MessageMaskingFailedException;
use App\Support\Messaging\MessageMasker;

/**
 * R230: the regression corpus for `MessageMasker`. Three jobs, all test-side (nothing here is shipped):
 *
 *  - `inputs()` builds the corpus: a few thousand synthetic bodies from a seeded generator of our own (a
 *    linear congruential generator, so the sequence cannot drift with the PHP version or its `mt_rand`
 *    implementation). Nothing in it is a real person's contact detail: example domains and `.test`, the
 *    mail-provider and short-link names the masker itself lists (it cannot be exercised without them),
 *    invented handles, and numbers that are either `555`-style or random digit strings.
 *  - `recorded()` reads `tests/Fixtures/Messaging/masker_corpus.jsonl`: the output of the accepted masker
 *    for each input, frozen and keyed by the sha1 of the input (so adding a row to `masking_fixtures.php` needs
 *    no re-recording; such a row is covered by the exact dataset test). `record()` writes it. Re-recording
 *    is a deliberate step (see the header of `MessageMaskerCorpusTest`): do it only from a checkout of
 *    `main`, never from a branch that changes the masker.
 *  - `neverExposes()` is the monotonicity check: a change may hide more than the baseline did, never less.
 *    `MessageMasker::mask()` returns text only, so the check works on the output: split both outputs on the
 *    placeholder and require every visible piece of the candidate to sit, in order and without overlap,
 *    inside one visible piece of the baseline. A candidate that shows a character the baseline hid has a
 *    piece that straddles a baseline placeholder (or sits outside the baseline's visible text), so it fails.
 *    Disclosed limits: (1) an exposed stretch that happens to repeat visible text elsewhere in the same body
 *    could align to that copy and pass (e.g. baseline "[hidden], sara" against "sara[hidden]"); sixteen
 *    deliberately weakened maskers were all caught in the review of this file, but the weakest spots are
 *    the phone-gap limit and MAIL_PROVIDER (one to three inputs each). (2) A change that only alters
 *    normalisation of visible text (say, also stripping the zero-width joiner) reads as an exposure: the
 *    fix is a deliberate re-recording from `main` in the same cycle. (3) Recording the whole output rather
 *    than hidden spans is a disclosed deviation from R230's wording (the masker exposes no spans and
 *    19a may not change `app/`).
 */
final class MaskerCorpus
{
    public const MIN_INPUTS = 6000;

    public const SEED = 230;

    public const FIXTURE = __DIR__.'/../../Fixtures/Messaging/masker_corpus.jsonl';

    public const BUDGET_MS = 50.0;

    /** @var list<string>|null */
    private static ?array $inputs = null;

    /** @var array<int, array{output: string|null, ms: float}>|null */
    private static ?array $runs = null;

    /** @var list<string>|null */
    private static ?array $sections = null;

    private int $state = self::SEED;

    private string $currentSection = '';

    /** @var array<string, string> input => section, in generation order */
    private array $seen = [];

    private const DIGIT_WORDS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];

    private const LEADS = ['', '', 'hi ', 'call me on ', 'my number is ', 'text ', 'contact: ', 'ok see ', 'please write to ', 'lesson tomorrow, ', "it's ", '→ ', '"', '(', 'reach me at ', 'or just '];

    private const TRAILS = ['', '', ' thanks', ' bye', '.', ', ok?', '!', ' tomorrow at 5', ' or ask my mum', ')', '"', "\n", ' 😀', ' see you at 16:00-17:00', '...'];

    private const MESSENGERS = ['whatsapp', 'WhatsApp', 'wa', 'telegram', 'tg', 'instagram', 'insta', 'ig', 'snapchat', 'snap', 'signal', 'viber', 'wechat', 'skype', 'discord', 'line', 'kik', 'imo', 'Telegram', 'IG'];

    private const HANDLES = ['@sara_k', 'sara.k99', 'sarak', 'sara_example', '@example_sara', 'user123', 'sara-k', 'saraK2000', '@s.a.r.a'];

    private const WORDS = ['hello', 'thanks', 'lesson', 'tomorrow', 'please', 'maths', 'online', 'school', 'shop', 'team', 'chat', 'page', 'link', 'app', 'pro', 'info', 'dev', 'com', 'net', 'org', 'see', 'you', 'at', 'five', 'grade', 'homework', 'ok', 'great', 'yes', 'no', 'later', 'today', 'next', 'week', 'book', 'join', 'our', 'room', 'ready', 'the', 'a', 'is', 'on', 'it', 'me', 'my', 'so', 'in', 'to', 'by', 'ae', 'uk', 'io', 'co', 'tv'];

    private const BENIGN = [
        'Ok. Online lessons are fine.', 'See you at 16:00-17:00', 'It costs 120.50 AED for 1.5 hours', 'Version 2.3.1 is out',
        'Grade 10, room 12, seat 7', 'Pi is about 3.14159', 'We start on 2026-10-18 at 17:30', 'Call it a day.', 'join our team',
        'have a chat', 'book shop opens at nine', 'Mr. Smith said so.', 'e.g. fractions, i.e. halves', 'Rated 4.5/5 by 120 parents',
        'Chapter 3.2 and 3.3', 'about 5-6 lessons a month', 'Q&A at 5.30 pm', 'Is it ok.com? no', 'Section 1.1.1',
        'in 1999 and 2000-2005', 'page 12-14', 'Tel. is not needed', 'at 5 o clock', 'twenty one students',
    ];

    private function __construct() {}

    /**
     * @return list<string>
     */
    public static function inputs(): array
    {
        if (self::$inputs === null) {
            $generator = new self;
            $generator->generate();
            self::$inputs = array_map('strval', array_keys($generator->seen));
        }

        return self::$inputs;
    }

    /** The recording is keyed by input, not by position, so adding a dataset row never shifts it. */
    public static function keyOf(string $input): string
    {
        return sha1($input);
    }

    /**
     * Runs the masker over every corpus input once, timing each. A first reading over the budget is
     * re-measured (best of four) so a scheduler hiccup on a busy CI runner is not read as a slow input.
     *
     * @return array<int, array{output: string|null, ms: float}>
     */
    public static function run(): array
    {
        if (self::$runs !== null) {
            return self::$runs;
        }

        $masker = new MessageMasker;
        $runs = [];

        foreach (self::inputs() as $index => $input) {
            $output = null;
            $best = INF;

            for ($attempt = 0; $attempt < 4; $attempt++) {
                $start = hrtime(true);

                try {
                    $output = $masker->mask($input)->text;
                } catch (MessageMaskingFailedException) {
                    $output = null;
                }

                $best = min($best, (hrtime(true) - $start) / 1_000_000);

                if ($best <= self::BUDGET_MS) {
                    break;
                }
            }

            $runs[$index] = ['output' => $output, 'ms' => $best];
        }

        return self::$runs = $runs;
    }

    /**
     * @return array{header: array<string, mixed>, outputs: array<string, string|null>} outputs keyed by sha1 of the input
     */
    public static function recorded(): array
    {
        $lines = file(self::FIXTURE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $header = json_decode((string) array_shift($lines), true, 512, JSON_THROW_ON_ERROR);
        $outputs = [];

        foreach ($lines as $line) {
            $outputs[substr($line, 0, 40)] = json_decode(substr($line, 41), true, 512, JSON_THROW_ON_ERROR);
        }

        return ['header' => $header, 'outputs' => $outputs];
    }

    /**
     * Writes the fixture from the masker as it is in this checkout: a header line, then one line per
     * input, `<sha1 of the input> <JSON output>`, in generator order so a diff shows exactly which inputs
     * changed their output.
     */
    public static function record(): int
    {
        $runs = self::run();
        $header = [
            'about' => 'MessageMasker corpus recording (R230). Regenerate: php tests/Support/Messaging/record_corpus.php',
            'seed' => self::SEED,
            'count' => count($runs),
            'placeholder' => MessageMasker::PLACEHOLDER,
        ];

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $lines = [json_encode($header, $flags)];

        foreach (self::inputs() as $index => $input) {
            $lines[] = self::keyOf($input).' '.json_encode($runs[$index]['output'], $flags);
        }

        file_put_contents(self::FIXTURE, implode("\n", $lines)."\n");

        return count($runs);
    }

    /**
     * True when the candidate output hides everything the baseline output hid (it may hide more). A null
     * output is a refused message, which hides everything: it is never an exposure.
     */
    public static function neverExposes(?string $baseline, ?string $candidate): bool
    {
        if ($baseline === null || $candidate === null) {
            return true;
        }

        $base = explode(MessageMasker::PLACEHOLDER, $baseline);
        $segment = 0;
        $offset = 0;

        foreach (explode(MessageMasker::PLACEHOLDER, $candidate) as $piece) {
            if ($piece === '') {
                continue;
            }

            while (true) {
                if ($segment >= count($base)) {
                    return false;
                }

                $at = strpos($base[$segment], $piece, $offset);

                if ($at !== false) {
                    $offset = $at + strlen($piece);

                    break;
                }

                $segment++;
                $offset = 0;
            }
        }

        return true;
    }

    /** Which generator section an input came from, for failure messages. */
    public static function sectionOf(int $index): string
    {
        if (self::$sections === null) {
            $generator = new self;
            $generator->generate();
            self::$sections = array_values($generator->seen);
        }

        return self::$sections[$index] ?? '?';
    }

    // ---------------------------------------------------------------------------------------------------
    // Generator

    private function generate(): void
    {
        $this->state = self::SEED;
        $this->seen = [];

        $this->section('urls', 950, fn () => $this->wrap($this->url()));
        $this->section('phones', 1250, fn () => $this->wrap($this->phone()));
        $this->section('emails', 1050, fn () => $this->wrap($this->email()));
        $this->section('spelled', 1050, fn () => $this->wrap($this->spelled()));
        $this->section('messengers', 700, fn () => $this->wrap($this->messenger()));
        $this->section('lookalikes', 750, fn () => $this->wrap($this->lookalike()));
        $this->section('long', 200, fn () => $this->longRun());
        $this->section('prose', 850, fn () => $this->prose());

        // The dataset rows come last and take no random numbers, so adding a row to masking_fixtures.php
        // never shifts or changes any synthetic input.
        $this->currentSection = 'dataset';

        foreach (require __DIR__.'/../../Fixtures/Messaging/masking_fixtures.php' as $row) {
            $this->add($row[0]);
        }
    }

    /**
     * @param  callable(): ?string  $make
     */
    private function section(string $name, int $quota, callable $make): void
    {
        $this->currentSection = $name;
        $target = count($this->seen) + $quota;
        $guard = $quota * 20;

        while (count($this->seen) < $target && $guard-- > 0) {
            $this->add($make());
        }
    }

    private function add(?string $input): void
    {
        if ($input !== null && ! array_key_exists($input, $this->seen)) {
            $this->seen[$input] = $this->currentSection;
        }
    }

    private function next(): int
    {
        $this->state = ($this->state * 1103515245 + 12345) & 0x7FFFFFFF;

        return $this->state >> 8;
    }

    private function int(int $min, int $max): int
    {
        return $min + $this->next() % ($max - $min + 1);
    }

    private function chance(int $percent): bool
    {
        return $this->int(1, 100) <= $percent;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[$this->next() % count($items)];
    }

    private function wrap(string $core): string
    {
        return $this->pick(self::LEADS).$core.$this->pick(self::TRAILS);
    }

    private function digits(int $count): string
    {
        $out = '';

        for ($i = 0; $i < $count; $i++) {
            $out .= (string) $this->int(0, 9);
        }

        return $out;
    }

    /** A fictional subscriber number: an area prefix, the 555 block, then 01 and two more digits. */
    private function fictionalNumber(): string
    {
        $prefix = $this->pick(['050', '055', '052', '04', '020', '0115', '07700', '212', '415', '971', '44', '91']);

        return $prefix.'555'.($this->chance(70) ? '01'.$this->digits(2) : $this->digits($this->int(2, 5)));
    }

    private function mapDigits(string $number, int $zero): string
    {
        return (string) preg_replace_callback('/[0-9]/', fn (array $m) => mb_chr($zero + (int) $m[0], 'UTF-8'), $number);
    }

    // ---- urls

    private function url(): string
    {
        $scheme = $this->pick(['', 'http://', 'https://', 'www.', 'https://www.', 'HTTPS://', 'ftp://', 'hxxp://']);
        $host = $this->pick(['example.com', 'sub.example.org', 'my-site.example.net', 'example.co.uk', 'example.test', 'wa.me', 't.me', 'bit.ly', 'example.shop', 'example.online', 'example.ae', 'site.example.xyz', 'a.b.c.example.com', 'EXAMPLE.COM', 'mysite.com', 'name.net', 'example.io']);
        $path = $this->pick(['', '', '/', '/a-b/c_d', '/a-b/c_d?x=1#y', '?x=1&y=2', '#frag', '/profile/sara.k', '/9715550123', '/@sara_k', '/a/b/c/d/e', '/path with space']);
        $after = $this->pick(['', '', '.', ',', ')', ' ', '/', '!']);

        if ($this->chance(15)) {
            // Spaced or word-separated bare domain.
            $host = $this->pick(['example . com', 'example .com', 'example. com', 'mysite . net', 'example dot com', 'example (dot) com', 'example [dot] com', 'ex ample.com']);
        }

        return $scheme.$host.$path.$after;
    }

    // ---- phones

    private function phone(): string
    {
        $number = $this->fictionalNumber();

        if ($this->chance(8)) {
            $number = $this->digits($this->int(5, 6));
        } elseif ($this->chance(8)) {
            $number = $this->digits(7);
        }

        if ($this->chance(25)) {
            $number = $this->mapDigits($number, $this->pick([0x0660, 0x06F0, 0xFF10]));
        }

        $groups = $this->pick([[3, 3, 4], [2, 3, 4], [4, 3, 3], [3, 4], [2, 2, 2, 2, 2], [1, 3, 3, 3], [5, 5], [3, 3, 3]]);
        $sep = $this->pick([' ', '-', '.', '/', ' - ', '_', '  ', '•', '·', '–', '—', '*', '|', ' / ', '😀', '(', '٫']);
        $chars = mb_str_split($number, 1, 'UTF-8');
        $out = '';
        $taken = 0;

        foreach ($groups as $size) {
            $out .= ($out === '' ? '' : $sep).implode('', array_slice($chars, $taken, $size));
            $taken += $size;
        }

        $out .= implode('', array_slice($chars, $taken));

        if ($this->chance(30)) {
            $out = $this->pick(['+971 ', '00971 ', '+44 ', '+1 ', '+91-', '+966 ', '(+971) ', '+971(0)']).$out;
        }

        if ($this->chance(15)) {
            $out = '('.$out.')';
        }

        return $out;
    }

    // ---- emails

    private function email(): string
    {
        $local = $this->pick(['sara', 'sara.k', 'sara+lessons', 's.a.r.a', 'sara_k-99', 'Sara.K', 'sara..k', 'a', 'x_y.z+tag', 'sara-k']);
        $host = $this->pick(['example.com', 'mail.example.com', 'a.b.example.org', 'example.co.uk', 'example.ae', 'example.test', 'example.net', 'gmail.com', 'mail.example.ae', 'sub.sub.example.io', 'example.online', 'example.shop']);
        $at = $this->pick(['@', '@', '@', ' @ ', '＠', '@@', ' @', '@ ', '﹫', '(@)', '[@]', '＠ ']);

        if ($this->chance(25)) {
            $host = str_replace('.', $this->pick(['.', ' . ', ' .', '. ', '․', ',', '-', '..', '·', '[.]', '(.)', '。', '．']), $host);
        }

        $email = $local.$at.$host;

        return $this->chance(10) ? '<'.$email.'>' : $email;
    }

    // ---- spelled-out shapes (18b): R218

    private function spelled(): string
    {
        if ($this->chance(45)) {
            return $this->spelledDigits();
        }

        $seps = ['', ' ', ' ', '_', '-', '.', ',', '  ', ' - ', '/', '|', '~', '*', ' . '];
        $sep = $this->pick($seps);
        $sep2 = $this->chance(60) ? $sep : $this->pick($seps);
        $at = $this->pick(['at', '(at)', '[at]', '{at}', 'AT', 'At', '(AT)', '[ at ]', 'a t', '<at>', ' at ']);
        $dot = $this->pick(['dot', '(dot)', '[dot]', '{dot}', 'DOT', 'Dot', 'd0t', 'd o t', '<dot>', 'point', ' dot ']);
        $local = $this->pick(['sara', 'sara.k', 'sarak', 'sara_k', 'Sara', 'name']);
        $label = $this->pick(['example', 'mail', 'example2', 'my-site']);
        $ending = $this->pick(['com', 'net', 'org', 'test', 'ae', 'co', 'online', 'io']);

        $out = $local.$sep.$at.$sep2.$label.$sep.$dot.$sep2.$ending;

        if ($this->chance(25)) {
            $out = $local.$sep.$at.$sep2.$label.$sep.$dot.$sep2.$this->pick(['co', 'uk']).$sep.$dot.$sep2.$ending;
        }

        if ($this->chance(15)) {
            // Only the dot is spelled, or only the at.
            $out = $this->chance(50) ? $label.$sep.$dot.$sep2.$ending : $local.$sep.$at.$sep2.$label.'.'.$ending;
        }

        return $out;
    }

    private function spelledDigits(): string
    {
        $count = $this->int(3, 13);
        $sep = $this->pick([' ', ' ', '-', ',', '.', '/', '  ', ', ', ' - ', '_', '']);
        $words = [];

        for ($i = 0; $i < $count; $i++) {
            $word = self::DIGIT_WORDS[$this->int(0, 9)];
            $words[] = match ($this->int(0, 9)) {
                0 => strtoupper($word),
                1 => ucfirst($word),
                2 => $word === 'zero' ? 'oh' : $word,
                default => $word,
            };
        }

        $run = implode($sep, $words);

        return $this->chance(30) ? $run.$sep.$this->digits($this->int(1, 4)) : $run;
    }

    // ---- messengers

    private function messenger(): string
    {
        $name = $this->pick(self::MESSENGERS);
        $joiner = $this->pick([' ', ': ', ' - ', ' me on ', ' id ', ' @ ', ' -> ', ' is ', "\n", ' me ', '/', ' no ', ' number ']);
        $tail = match ($this->int(0, 3)) {
            0 => $this->pick(self::HANDLES),
            1 => $this->fictionalNumber(),
            2 => $this->pick(self::HANDLES).' '.$this->fictionalNumber(),
            default => $this->pick(['same number', 'the same', 'ask me', 'me', 'lessons', 'ok', 'group', 'call']),
        };

        $out = $name.$joiner.$tail;

        return $this->chance(20) ? $this->pick(['add me on ', 'find me on ', 'msg ', 'dm ']).$out : $out;
    }

    // ---- mixed scripts and lookalikes

    private function lookalike(): string
    {
        $core = match ($this->int(0, 3)) {
            0 => $this->email(),
            1 => $this->phone(),
            2 => $this->url(),
            default => $this->messenger(),
        };

        $mutations = $this->int(1, 3);

        for ($i = 0; $i < $mutations; $i++) {
            $core = $this->mutate($core);
        }

        return $this->chance(25)
            ? $this->pick(['مرحبا ', 'שלום ', '你好 ', 'привет ', 'ありがとう ', 'γειά σου ', 'नमस्ते ']).$core
            : $core;
    }

    private function mutate(string $text): string
    {
        $chars = mb_str_split($text, 1, 'UTF-8');

        if ($chars === []) {
            return $text;
        }

        $at = $this->int(0, count($chars) - 1);

        switch ($this->int(0, 5)) {
            case 0:
                array_splice($chars, $at, 0, [$this->pick(["\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}", "\u{FEFF}", "\u{00AD}", "\u{200E}", "\u{202E}", "\u{034F}", "\u{FE0F}"])]);
                break;
            case 1:
                $map = ['a' => "\u{0430}", 'e' => "\u{0435}", 'o' => "\u{043E}", 'c' => "\u{0441}", '@' => "\u{FF20}", '.' => "\u{FF0E}", '0' => "\u{0660}", '1' => "\u{FF11}", '5' => "\u{06F5}"];
                foreach ($chars as $i => $c) {
                    if (isset($map[$c]) && $i >= $at) {
                        $chars[$i] = $map[$c];
                        break;
                    }
                }
                break;
            case 2:
                array_splice($chars, $at + 1, 0, ["\u{0301}"]);
                break;
            case 3:
                $chars = array_map(fn (string $c) => ($c >= '!' && $c <= '~') ? mb_chr(0xFF01 + ord($c) - 0x21, 'UTF-8') : $c, $chars);
                break;
            case 4:
                foreach ($chars as $i => $c) {
                    if (ctype_digit($c)) {
                        $chars[$i] = $c === '0' ? "\u{24EA}" : mb_chr($this->pick([0x2460, 0x2776, 0x2780, 0x278A, 0x24F5]) + (int) $c - 1, 'UTF-8');
                    }
                }
                break;
            default:
                array_unshift($chars, "\u{200F}");
                $chars[] = "\u{200E}";
        }

        return implode('', $chars);
    }

    // ---- long runs, up to the 2000-character cap

    private function longRun(): string
    {
        $unit = $this->pick(['.', '_', '-', ' .', '. ', '!!', '@', 'a.', 'a_', 'a-', '1 ', '1-', ', ', '..', '__', '- ', 'ab ', '1.', 'x@']);
        $length = $this->pick([50, 120, 300, 600, 1000, 1500, 1990, 2000]);
        $pad = mb_substr(str_repeat($unit, intdiv($length, strlen($unit)) + 1), 0, $length, 'UTF-8');
        $core = $this->chance(70) ? $this->pick([$this->email(), $this->phone(), $this->url(), $this->spelled(), 'sara@example.com', '050 555 0123', 'example.com']) : '';

        $body = match ($this->int(0, 2)) {
            0 => $core.' '.$pad,
            1 => $pad.' '.$core,
            default => mb_substr($pad, 0, intdiv($length, 2), 'UTF-8').' '.$core.' '.mb_substr($pad, intdiv($length, 2), null, 'UTF-8'),
        };

        return mb_substr($body, 0, 2000, 'UTF-8');
    }

    // ---- ordinary prose

    private function prose(): string
    {
        if ($this->chance(30)) {
            return $this->pick(self::BENIGN).$this->pick(self::TRAILS);
        }

        $count = $this->int(2, 12);
        $out = '';

        for ($i = 0; $i < $count; $i++) {
            $out .= ($i === 0 ? '' : $this->pick([' ', ' ', ' ', '. ', ', ', '.', '-', ' - ', '! ', ' . ', '..', ' / ']));
            $out .= $this->chance(10) ? (string) $this->int(0, 99999) : $this->pick(self::WORDS);
        }

        return $out;
    }
}
