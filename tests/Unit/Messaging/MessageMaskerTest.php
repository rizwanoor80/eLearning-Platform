<?php

use App\Exceptions\MessageMaskingFailedException;
use App\Support\Messaging\MaskedMessage;
use App\Support\Messaging\MessageMasker;

// The fixture table is the specification (R134): [input, expected stored text].
dataset('masking fixtures', require __DIR__.'/../../Fixtures/Messaging/masking_fixtures.php');

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

// R144(b): removing the junk-gap caps above reopens the superlinear-backtracking shape the round-4
// review's Finding 2 flagged for the bare-domain patterns (NAMED_DOMAIN_LOOSE/_TIGHT/COUNTRY_DOMAIN),
// whose outer group stays fully backtracking (see the class docblock). `hasCandidate()`'s presence gate
// is what actually keeps these inputs cheap — measured directly here, not assumed — against the three
// shapes the finding named: a body with no valid domain ending anywhere, the finding-1 repro shapes
// padded well past the old cap, and a mixed adversarial body combining both junk and lookalike-ending
// words that are not actually on any ending list.
it('stays fast on adversarial junk-gap input (R144 Finding 2)', function (string $label, string $body) {
    $start = hrtime(true);
    $result = (new MessageMasker)->mask($body);
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;

    expect(preg_last_error())->toBe(PREG_NO_ERROR)
        ->and($elapsedMs)->toBeLessThan(50.0, "{$label} took {$elapsedMs}ms, over the 50ms R144(b) budget")
        ->and($result)->toBeInstanceOf(MaskedMessage::class);
})->with([
    'no domain ending anywhere: str_repeat("a.", 1000)' => ['no ending', str_repeat('a.', 1000)],
    'finding-1 shape padded to 2000 chars (dots)' => ['padded dots', 'sara@name'.str_repeat('.', 2000).'com'],
    'finding-1 shape padded to 2000 chars (spaces)' => ['padded spaces', 'sara@name'.str_repeat(' ', 2000).'.com'],
    'mixed adversarial: junk and non-ending words' => ['mixed junk', str_repeat('word- . ,text', 150)],

    // R149(b): the class docblock's disclosed gate-open gap, measured empirically at the time (a
    // benign-looking `str_repeat('a-', 20).' ok'`, 43 chars, already exhausted the default
    // `pcre.backtrack_limit`) — every case above deliberately contains none of NAMED_DOMAIN_LOOSE/
    // _TIGHT/COUNTRY_DOMAIN's ending tokens, so `hasCandidate()`'s gate stays closed and they only ever
    // measure the gate working, not the cost once it opens. These four open the gate on purpose (the
    // ending token IS present) while still containing a long dash run, the exact `(a+)+` shape
    // BARE_DOMAIN_LABEL/MAX_BARE_DOMAIN_LABELS now forecloses rather than merely bounds.
    'gate-open repro: the exact disclosed shape, a country-code-like ending' => ['gate-open repro', str_repeat('a-', 20).' ok'],
    'gate-open, long dash run before a non-word ending' => ['gate-open non-word', str_repeat('a-', 1000).'.com'],
    'gate-open, long dash run before a word-like ending' => ['gate-open word-like', str_repeat('a-', 1000).'.shop'],
    'gate-open, mixed dot/dash run before a non-word ending' => ['gate-open mixed', str_repeat('a-', 500).str_repeat('a.', 500).'.com'],
]);

// R157(c): EMAIL is not gated by `hasCandidate()` (it runs unconditionally in the masking pipeline,
// unlike NAMED_DOMAIN_LOOSE/_TIGHT/COUNTRY_DOMAIN above), and unlike those ending-anchored patterns its
// outer domain group was already possessive — so neither of R144 Finding 2's fixes applied to it as
// written. The actual adversarial position, confirmed empirically and by advisor review, is EMAIL's
// domain *first label*: pre-fix it shared EMAIL's own `[\p{L}\p{Nd}._%+\-]` class with LABEL_GAP's `-`,
// so a domain that never resolves to two valid labels (an `@` followed by a long dash run with no
// second label) made the first label's greedy match backtrack against LABEL_GAP one dash at a time.
// Unfixed, this exhausted the default `pcre.backtrack_limit` at ~2000 dashes (measured directly against
// the pre-fix pattern) — a fail-closed `MessageMaskingFailedException`, not a leak, but a legitimate
// message of ordinary length being refused. The fix reuses BARE_DOMAIN_LABEL (no `-`) for the domain
// side of EMAIL, the same disjoint-character-class mechanism R149(b) used for the bare-domain patterns
// — deliberately not MAX_BARE_DOMAIN_LABELS' iteration cap, which would be actively harmful here (EMAIL
// is anchored at `@`, not an ending list, so capping the outer group would leak labels beyond the cap
// as plain text instead of safely shifting the match). Note the plan text for R157(c) named the local
// part as the adversarial fixture; the local part's own class was already disjoint from `@` and stays
// O(n) at every length tested — the fixture below targets the domain's first label instead, which is
// where the empirical failure actually was.
it('stays fast on an EMAIL domain that never resolves to a second label (R157(c))', function (string $label, string $body) {
    $start = hrtime(true);
    $result = (new MessageMasker)->mask($body);
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;

    expect(preg_last_error())->toBe(PREG_NO_ERROR)
        ->and($elapsedMs)->toBeLessThan(50.0, "{$label} took {$elapsedMs}ms, over the 50ms R144(b)/R157(c) budget")
        ->and($result)->toBeInstanceOf(MaskedMessage::class);
})->with([
    // The exact shape that exhausted the default pcre.backtrack_limit pre-fix (measured at n=2000).
    'dash run after @, no second label, 2000 dashes' => ['dash-run 2000', 'sara@a'.str_repeat('-', 2000)],
    'dash run after @, no second label, 8000 dashes' => ['dash-run 8000', 'sara@a'.str_repeat('-', 8000)],
    // Same shape with legitimate text following, to confirm the fixture is not just "runs off the end
    // of the string with nothing left to backtrack into".
    'dash run after @, trailing word, 2000 dashes' => ['dash-run trailing', 'x@'.str_repeat('-', 2000).' ok'],
    // The plan's own framing: a long adversarial local part. Kept for completeness, truthfully labeled
    // as O(n) rather than as the adversarial position (see comment above the test).
    '2000-char local part, no @ at all' => ['long local part', str_repeat('a', 2000)],
]);

// R144(b): a genuine PCRE engine failure — forced here via a backtrack limit far below what any real
// match needs, rather than relying on accidentally hitting the default limit — must still fail the
// message closed (`orFail()`/`hasCandidate()`), never let the original text through unmasked.
//
// R149(a): asserts the concrete `MessageMaskingFailedException`, not the bare `RuntimeException` it
// extends. `MessageController::store()` catches only the subclass; a bare-`RuntimeException` assertion
// here would stay green even if a throw site regressed to `throw new RuntimeException(...)`, while the
// controller's catch silently stopped firing.
it('still fails closed on a genuine PCRE engine failure', function () {
    $original = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1');

    try {
        (new MessageMasker)->mask('sara@gmail.com');
        expect(false)->toBeTrue('expected mask() to throw when PCRE cannot complete the match');
    } catch (MessageMaskingFailedException $e) {
        expect($e->getMessage())->toContain('could not be masked');
    } finally {
        ini_set('pcre.backtrack_limit', $original);
    }
});
