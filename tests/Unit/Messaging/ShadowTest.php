<?php

use App\Support\Messaging\Shadow;

// R231: `Shadow` is the per-byte origin map `MessageMasker` uses to hide spans of the ORIGINAL text.

it('maps an untouched copy onto itself', function () {
    $shadow = Shadow::identity('abc');

    expect($shadow->text)->toBe('abc')
        ->and($shadow->from)->toBe([0, 1, 2])
        ->and($shadow->to)->toBe([1, 2, 3])
        ->and($shadow->placeholderSpans())->toBe([])
        ->and(Shadow::identity('')->text)->toBe('');
});

it('lets a replacement stand for every byte it replaced', function () {
    // "a at b" -> "a@b": the "@" comes from the 4 bytes " at "
    $shadow = Shadow::identity('a at b')->apply([[1, 4, [['@', 0, 4]]]]);

    expect($shadow->text)->toBe('a@b')
        ->and($shadow->from)->toBe([0, 1, 5])
        ->and($shadow->to)->toBe([1, 5, 6]);
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
