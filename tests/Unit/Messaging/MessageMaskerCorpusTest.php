<?php

use App\Support\Messaging\MessageMasker;
use Tests\Support\Messaging\MaskerCorpus;

/*
 * R230: the MessageMasker regression corpus.
 *
 * MaskerCorpus builds a few thousand synthetic bodies from a seeded generator; the fixture
 * tests/Fixtures/Messaging/masker_corpus.jsonl holds what the accepted masker (`main` at the time of
 * recording) output for each, in order. The test asserts that the masker in this checkout still hides
 * everything that recording hid — it may hide more, never less — and that no input takes over 50 ms.
 *
 * A change to MessageMasker that turns the monotonicity test red has exposed something `main` hid: fix
 * the change, do not touch the fixture. Regenerating the fixture is a deliberate step, taken only from a
 * checkout whose masker is the accepted baseline, and committed on its own with the reason in the message:
 *
 *     php tests/Support/Messaging/record_corpus.php
 *
 * Changing the generator changes the inputs, which turns the input-hash test red until the fixture is
 * re-recorded the same way. Inputs are synthetic only: example domains, `.test`, fictional handles and
 * 555-style numbers.
 */

it('builds a corpus of at least 6000 distinct synthetic inputs', function () {
    $inputs = MaskerCorpus::inputs();

    expect(count($inputs))->toBeGreaterThanOrEqual(MaskerCorpus::MIN_INPUTS)
        ->and(count(array_unique($inputs)))->toBe(count($inputs));
});

it('records an output for exactly the inputs the generator builds', function () {
    $recorded = MaskerCorpus::recorded();

    expect($recorded['header']['inputs_sha1'])->toBe(MaskerCorpus::inputsHash(), 'the generator changed: re-record the fixture (see the header of this file)')
        ->and($recorded['header']['count'])->toBe(count(MaskerCorpus::inputs()))
        ->and(count($recorded['outputs']))->toBe(count(MaskerCorpus::inputs()));
});

it('still hides everything the recorded masker hid (a change may hide more, never less)', function () {
    $recorded = MaskerCorpus::recorded()['outputs'];
    $inputs = MaskerCorpus::inputs();
    $exposures = [];

    foreach (MaskerCorpus::run() as $index => $run) {
        if (! MaskerCorpus::neverExposes($recorded[$index], $run['output'])) {
            $exposures[] = sprintf(
                "#%d [%s]\n  input:    %s\n  recorded: %s\n  now:      %s",
                $index,
                MaskerCorpus::sectionOf($index),
                json_encode($inputs[$index], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                json_encode($recorded[$index], JSON_UNESCAPED_UNICODE),
                json_encode($run['output'], JSON_UNESCAPED_UNICODE),
            );
        }
    }

    expect($exposures)->toBe([], count($exposures)." input(s) now show text the recorded masker hid:\n".implode("\n", array_slice($exposures, 0, 10)));
});

it('keeps every corpus input under the 50 ms budget', function () {
    $slow = [];

    foreach (MaskerCorpus::run() as $index => $run) {
        if ($run['ms'] > MaskerCorpus::BUDGET_MS) {
            $slow[] = sprintf('#%d [%s] %.1f ms', $index, MaskerCorpus::sectionOf($index), $run['ms']);
        }
    }

    expect($slow)->toBe([], 'inputs over the '.MaskerCorpus::BUDGET_MS.' ms budget: '.implode('; ', $slow));
});

it('refuses no corpus input outright', function () {
    $refused = array_keys(array_filter(MaskerCorpus::run(), fn (array $run) => $run['output'] === null));

    expect($refused)->toBe([], 'the masker threw on corpus input(s) #'.implode(', #', $refused));
});

// The checker's own tests: the corpus test is only as good as neverExposes().
describe('monotonicity checker', function () {
    $p = MessageMasker::PLACEHOLDER;

    it('accepts an identical output', function () use ($p) {
        expect(MaskerCorpus::neverExposes("call {$p} now", "call {$p} now"))->toBeTrue();
    });

    it('accepts an output that hides more', function () use ($p) {
        expect(MaskerCorpus::neverExposes("call {$p} now", "{$p} now"))->toBeTrue()
            ->and(MaskerCorpus::neverExposes("call {$p} now", "call {$p}"))->toBeTrue()
            ->and(MaskerCorpus::neverExposes("call {$p} now", "cal{$p}ow"))->toBeTrue()
            ->and(MaskerCorpus::neverExposes("call {$p} now", $p))->toBeTrue()
            ->and(MaskerCorpus::neverExposes('call 050 now', "call {$p} now"))->toBeTrue();
    });

    it('rejects an output that shows what the baseline hid', function () use ($p) {
        expect(MaskerCorpus::neverExposes("call {$p} now", 'call 0501234567 now'))->toBeFalse()
            ->and(MaskerCorpus::neverExposes("call {$p} now", "call {$p}4567 now"))->toBeFalse()
            ->and(MaskerCorpus::neverExposes("call {$p} now", "call 0501{$p} now"))->toBeFalse()
            ->and(MaskerCorpus::neverExposes("call {$p} now", 'call now'))->toBeFalse();
    });

    it('rejects a hidden span that shrank or moved', function () use ($p) {
        expect(MaskerCorpus::neverExposes("a {$p} b {$p} c", "a {$p} b x c"))->toBeFalse()
            ->and(MaskerCorpus::neverExposes("a {$p} b {$p} c", "a b {$p} c"))->toBeFalse()
            ->and(MaskerCorpus::neverExposes("a {$p} b {$p} c", "a {$p} b {$p} c d"))->toBeFalse();
    });

    it('treats a refused message as hiding everything', function () use ($p) {
        expect(MaskerCorpus::neverExposes("call {$p}", null))->toBeTrue()
            ->and(MaskerCorpus::neverExposes(null, 'anything'))->toBeTrue();
    });

    it('rejects the unmasked input for every corpus input the recording hid something in', function () use ($p) {
        $recorded = MaskerCorpus::recorded()['outputs'];
        $accepted = [];

        foreach (MaskerCorpus::inputs() as $index => $input) {
            if ($recorded[$index] !== null && str_contains($recorded[$index], $p) && MaskerCorpus::neverExposes($recorded[$index], $input)) {
                $accepted[] = $index;
            }
        }

        expect($accepted)->toBe([], 'the checker accepted the raw input of corpus #'.implode(', #', $accepted));
    });
});
