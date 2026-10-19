<?php

use App\Support\Messaging\Shadow;

// R231: `Shadow` is the per-byte origin map `MessageMasker` uses to hide spans of the ORIGINAL text.

it('maps an untouched copy onto itself', function () {
    $shadow = Shadow::identity('abc');

    expect($shadow->text)->toBe('abc')
        ->and($shadow->fromList())->toBe([0, 1, 2])
        ->and($shadow->toList())->toBe([1, 2, 3])
        ->and($shadow->placeholderSpans())->toBe([])
        ->and(Shadow::identity('')->text)->toBe('');
});

it('lets a replacement stand for every byte it replaced', function () {
    // "a at b" -> "a@b": the "@" comes from the 4 bytes " at "
    $shadow = Shadow::identity('a at b')->apply([[1, 4, [['@', 0, 4]]]]);

    expect($shadow->text)->toBe('a@b')
        ->and($shadow->fromList())->toBe([0, 1, 5])
        ->and($shadow->toList())->toBe([1, 5, 6]);
});

it('returns a placeholder span in original-text bytes, widened over a rewritten stretch', function () {
    $shadow = Shadow::identity('x sara at gmail dot com y')
        ->apply([[6, 4, [['@', 0, 4]]], [15, 5, [['.', 0, 5]]]]);

    expect($shadow->text)->toBe('x sara@gmail.com y');

    $hidden = $shadow->apply([[2, 14, [['[p]', 0, 14]]]], true);

    expect($hidden->text)->toBe('x [p] y')
        ->and($hidden->placeholderSpans())->toBe([[2, 23]]);
});

it('widens a placeholder that swallows part of an earlier one to the whole of both', function () {
    $first = Shadow::identity('0123456789')->apply([[2, 3, [['[a]', 0, 3]]]], true);
    $second = $first->apply([[1, 4, [['[b]', 0, 4]]]], true);

    expect($second->text)->toBe('0[b]56789')
        ->and($second->placeholderSpans())->toBe([[1, 5]]);
});

it('keeps separate placeholders separate and numbered apart', function () {
    $shadow = Shadow::identity('ab cd ef')->apply([[0, 2, [['X', 0, 2]]], [6, 2, [['X', 0, 2]]]], true);

    expect($shadow->text)->toBe('X cd X')
        ->and($shadow->placeholderSpans())->toBe([[0, 2], [6, 8]]);
});

it('treats no edits as no change', function () {
    $shadow = Shadow::identity('abc');

    expect($shadow->apply([]))->toBe($shadow);
});

it('refuses edits that are out of order, overlap, run past the text or stand for nothing', function (array $edits) {
    Shadow::identity('abcdef')->apply($edits);
})->with([
    'out of order' => [[[3, 1, [['x', 0, 1]]], [1, 1, [['y', 0, 1]]]]],
    'overlapping' => [[[1, 3, [['x', 0, 3]]], [2, 1, [['y', 0, 1]]]]],
    'past the end' => [[[5, 3, [['x', 0, 3]]]]],
    'an empty piece' => [[[1, 2, [['x', 1, 1]]]]],
])->throws(LogicException::class);

it('keeps hundreds of placeholders separate, correct and in order once their ids pass 255', function () {
    $count = 600;
    $text = implode(' ', array_fill(0, $count, 'ab'));
    $edits = [];
    $expected = [];

    for ($i = 0; $i < $count; $i++) {
        $edits[] = [$i * 3, 2, [['X', 0, 2]]];
        $expected[] = [$i * 3, $i * 3 + 2];
    }

    $shadow = Shadow::identity($text)->apply($edits, true);

    expect($shadow->text)->toBe(implode(' ', array_fill(0, $count, 'X')))
        ->and($shadow->placeholderSpans())->toBe($expected);
});

it('widens spans correctly when a placeholder id has no zero byte left in it', function () {
    // 300 placeholders, then one edit that swallows the last two: ids 299 and 300 are 0x012B and 0x012C.
    $count = 300;
    $edits = [];

    for ($i = 0; $i < $count; $i++) {
        $edits[] = [$i * 3, 2, [['X', 0, 2]]];
    }

    $first = Shadow::identity(implode(' ', array_fill(0, $count, 'ab')))->apply($edits, true);
    $last = strlen($first->text);
    $merged = $first->apply([[$last - 3, 3, [['Y', 0, 3]]]], true);
    $spans = $merged->placeholderSpans();

    expect($spans)->toHaveCount($count - 1)
        ->and($spans[0])->toBe([0, 2])
        ->and(end($spans))->toBe([($count - 2) * 3, ($count - 1) * 3 + 2]);
});

it('reads the span of one placeholder that covers many bytes in a single pass', function () {
    // R235: one placeholder id over 100,000 bytes used to be read a byte at a time.
    $length = 100000;
    $shadow = Shadow::identity(str_repeat('a', $length))
        ->apply([[0, $length, [[str_repeat('Y', $length), 0, $length]]]], true);

    $start = hrtime(true);
    $spans = $shadow->placeholderSpans();
    $milliseconds = (hrtime(true) - $start) / 1e6;

    expect($spans)->toBe([[0, $length]])
        ->and($milliseconds)->toBeLessThan(60.0);
});

it('reads many separate placeholders without losing or merging any', function () {
    $count = 20000;
    $edits = [];

    for ($i = 0; $i < $count; $i++) {
        $edits[] = [$i * 3, 2, [['X', 0, 2]]];
    }

    $spans = Shadow::identity(implode(' ', array_fill(0, $count, 'ab')))->apply($edits, true)->placeholderSpans();

    expect($spans)->toHaveCount($count)
        ->and($spans[0])->toBe([0, 2])
        ->and($spans[$count - 1])->toBe([($count - 1) * 3, ($count - 1) * 3 + 2]);
});
