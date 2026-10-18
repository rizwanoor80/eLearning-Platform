<?php

use App\Support\Messaging\MessageMasker;
use Tests\Support\Messaging\MaskerCorpus;

/*
 * R230: the MessageMasker regression corpus.
 *
 * MaskerCorpus builds a few thousand synthetic bodies from a seeded generator; the fixture
 * tests/Fixtures/Messaging/masker_corpus.jsonl holds what the accepted masker (`main` at the time of
 * recording) output for each, keyed by the sha1 of the input. The test asserts that the masker in this checkout still hides
 * everything that recording hid — it may hide more, never less — and that no input takes over 50 ms.
 *
 * A change to MessageMasker that turns the monotonicity test red has exposed something `main` hid: fix
 * the change, do not touch the fixture. Regenerating the fixture is a deliberate step, taken only from a
 * checkout whose masker is the accepted baseline, and committed on its own with the reason in the message:
 *
 *     php tests/Support/Messaging/record_corpus.php
 *
 * Changing the generator changes its synthetic inputs, which turns the coverage test red until the fixture is
 * re-recorded the same way. Adding a row to the dataset in tests/Fixtures/Messaging/masking_fixtures.php
 * needs no re-recording: the corpus appends those rows last, and one with no recording is covered by the
 * exact dataset test instead. Inputs are synthetic only: example domains, `.test`, fictional handles and
 * 555-style numbers.
 */

it('builds a corpus of at least 6000 distinct synthetic inputs', function () {
    $inputs = MaskerCorpus::inputs();

    expect(count($inputs))->toBeGreaterThanOrEqual(MaskerCorpus::MIN_INPUTS)
        ->and(count(array_unique($inputs)))->toBe(count($inputs));
});

it('has a recorded output for every synthetic input the generator builds', function () {
    $recorded = MaskerCorpus::recorded();
    $missing = [];
    $covered = 0;

    foreach (MaskerCorpus::inputs() as $index => $input) {
        if (array_key_exists(MaskerCorpus::keyOf($input), $recorded['outputs'])) {
            $covered++;
        } elseif (MaskerCorpus::sectionOf($index) !== 'dataset') {
            $missing[] = $index;
        }
    }

    expect($recorded['header']['placeholder'])->toBe(MessageMasker::PLACEHOLDER, 'the placeholder text changed: re-record from main (see the header of this file)')
        ->and($missing)->toBe([], 'the generator changed: re-record from main (see the header of this file); no recording for #'.implode(', #', array_slice($missing, 0, 10)))
        ->and($covered)->toBeGreaterThanOrEqual(MaskerCorpus::MIN_INPUTS);
});

it('puts the dataset rows after every synthetic input, so adding a row never shifts the recording', function () {
    $sections = array_map(fn (int $index) => MaskerCorpus::sectionOf($index), array_keys(MaskerCorpus::inputs()));
    $firstDataset = array_search('dataset', $sections, true);

    expect($firstDataset)->not->toBeFalse()
        ->and(array_unique(array_slice($sections, $firstDataset)))->toBe(['dataset']);
});

it('still hides everything the recorded masker hid (a change may hide more, never less)', function () {
    $recorded = MaskerCorpus::recorded()['outputs'];
    $inputs = MaskerCorpus::inputs();
    $exposures = [];

    foreach (MaskerCorpus::run() as $index => $run) {
        $key = MaskerCorpus::keyOf($inputs[$index]);

        if (! array_key_exists($key, $recorded)) {
            continue; // an unrecorded dataset row; the coverage test above fails for any other
        }

        if (! MaskerCorpus::neverExposes($recorded[$key], $run['output'])) {
            $exposures[] = sprintf(
                "#%d [%s]\n  input:    %s\n  recorded: %s\n  now:      %s",
                $index,
                MaskerCorpus::sectionOf($index),
                json_encode($inputs[$index], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                json_encode($recorded[$key], JSON_UNESCAPED_UNICODE),
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
            $baseline = $recorded[MaskerCorpus::keyOf($input)] ?? null;

            if ($baseline !== null && str_contains($baseline, $p) && MaskerCorpus::neverExposes($baseline, $input)) {
                $accepted[] = $index;
            }
        }

        expect($accepted)->toBe([], 'the checker accepted the raw input of corpus #'.implode(', #', $accepted));
    });
});
