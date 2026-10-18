<?php

use App\Exceptions\MessageMaskingFailedException;
use App\Rules\DisplayNameRule;
use App\Support\Messaging\MessageMasker;
use App\Support\Messaging\Shadow;
use App\Support\Messaging\SpelledOutPrePass;
use Tests\Support\Messaging\MaskerCorpus;

/*
 * R231: the spelled-out pre-pass. The exact outputs are pinned by the `masking fixtures` dataset (positives,
 * negatives and the documented over-masking); the corpus test pins "never exposes what `main` hid". These tests
 * pin the three properties the design rests on: the tracked chain IS the plain chain, the pre-pass only ever
 * hides more, and it stays inside the 50 ms budget on its own worst shapes.
 */

function chainOf(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(MessageMasker::class, $method))->invoke(new MessageMasker, ...$arguments);
}

it('runs the chain over an untouched tracked copy exactly as the plain chain runs it (every corpus input)', function () {
    $different = [];

    foreach (MaskerCorpus::inputs() as $index => $input) {
        if (! mb_check_encoding($input, 'UTF-8')) {
            continue;
        }

        $plain = chainOf('maskPlain', $input)->text;
        $tracked = chainOf('chainShadow', Shadow::identity($input))->text;

        if ($plain !== $tracked) {
            $different[] = $index;
        }
    }

    expect($different)->toBe([], 'tracked chain differs from the plain chain on corpus input(s): '.implode(', ', array_slice($different, 0, 10)));
});

it('only ever hides more than the same body masked without the pre-pass (every corpus input)', function () {
    $exposed = [];

    foreach (MaskerCorpus::inputs() as $index => $input) {
        $without = (new MessageMasker)->mask($input, false)->text;
        $with = (new MessageMasker)->mask($input)->text;

        if (! MaskerCorpus::neverExposes($without, $with)) {
            $exposed[] = $index;
        }

        expect(mb_check_encoding($with, 'UTF-8'))->toBeTrue("#{$index} is not valid UTF-8");
    }

    expect($exposed)->toBe([], 'pre-pass exposed text on corpus input(s): '.implode(', ', array_slice($exposed, 0, 10)));
});

it('leaves a body with no spelled-out shape byte-identical to the plain chain', function (string $input) {
    $masker = new MessageMasker;

    expect($masker->mask($input)->text)->toBe($masker->mask($input, false)->text)
        ->and($masker->mask($input)->masked)->toBe($masker->mask($input, false)->masked);
})->with([
    'ordinary prose' => ['See you at school. Then we go home.'],
    'a plain address' => ['mail sara@example.com now'],
    'a plain number' => ['call 0501234567'],
    'nothing at all' => [''],
]);

it('keeps the sender\'s own words, accents included, everywhere but where it hides', function () {
    $result = (new MessageMasker)->mask('Café très bien — sara at example dot com ok à bientôt');

    expect($result->text)->toBe('Café très bien — '.MessageMasker::PLACEHOLDER.' ok à bientôt')
        ->and($result->masked)->toBeTrue();
});

it('is idempotent on a spelled-out body', function () {
    $masker = new MessageMasker;
    $once = $masker->mask('mail sara at gmail dot com or zero five zero one two three four five or whatsapp @sara_k')->text;

    expect($masker->mask($once)->text)->toBe($once);
});

it('is not applied to a display name (R218: no new rejection)', function () {
    expect(DisplayNameRule::refusal('Sara at gmail dot com'))->toBeNull();
});

it('finds a messenger name and the handle or digits after it', function (string $text, array $spans) {
    expect(SpelledOutPrePass::messengerSpans($text))->toBe($spans);
})->with([
    'handle' => ['hi whatsapp @sara_k ok', [[3, 19]]],
    'digits' => ['telegram 0501234', [[0, 16]]],
    'two words between' => ['my insta is the @sara_k', [[3, 23]]],
    'four words between is too far' => ['telegram a b c d @sara_k', []],
    'short number' => ['whatsapp 1234', []],
    'bare word' => ['telegram sara_k', []],
    'inside a longer word' => ['a signalman @sara_k', []],
]);

it('rewrites nothing in ordinary prose', function (string $text) {
    expect(SpelledOutPrePass::rewrite($text))->toBeNull();
})->with([
    'at home' => ['I am at home. Also fine'],
    'polka dot' => ['polka dot is cute'],
    'four digit words' => ['one two three four'],
]);

it('stays fast on its own worst shapes', function (string $label, string $body) {
    $start = hrtime(true);
    $result = (new MessageMasker)->mask($body);
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;

    expect(preg_last_error())->toBe(PREG_NO_ERROR)
        ->and($elapsedMs)->toBeLessThan(50.0, "{$label} took {$elapsedMs}ms, over the 50ms budget")
        ->and($result->text)->toBeString();
})->with([
    'many ats' => ['ats', str_repeat('a at ', 500)],
    'many dots' => ['dots', str_repeat('a dot ', 500)],
    'at and dot chain' => ['chain', str_repeat('sara at gmail dot ', 150)],
    'many digit words' => ['digits', str_repeat('one ', 700)],
    'digit words with commas' => ['commas', str_repeat('one, ', 600)],
    'many messenger names' => ['messengers', str_repeat('whatsapp ', 300)],
    'messenger and a long junk run' => ['messenger junk', 'whatsapp '.str_repeat('. ', 1000).'@sara_k'],
    'messenger and many words' => ['messenger words', 'whatsapp '.str_repeat('word ', 500)],
    'at and a 2000-dot run' => ['at dots', 'sara at gmail'.str_repeat('.', 2000).'com'],
    'at and a 2000-space run' => ['at spaces', 'sara at'.str_repeat(' ', 2000).'gmail.com'],
    'dot and 2000-space gaps' => ['dot gaps', 'a'.str_repeat(' ', 2000).'dot'.str_repeat(' ', 2000).'com'],
    'bracketed ats' => ['brackets', str_repeat('(at)', 500)],
    'dash runs round an at' => ['dash at', str_repeat('a-', 1000).' at '.str_repeat('b-', 1000).'.com'],
    'everything at once' => ['all', str_repeat('sara (at) a-b dot co dot uk zero one two three four five whatsapp @x_yz ', 40)],
]);

it('still fails closed on a PCRE failure inside the pre-pass', function () {
    $original = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1');

    try {
        expect(fn () => (new MessageMasker)->mask('sara at gmail dot com'))->toThrow(MessageMaskingFailedException::class);
    } finally {
        ini_set('pcre.backtrack_limit', $original);
    }
});
