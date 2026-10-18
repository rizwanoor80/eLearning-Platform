<?php

/**
 * Re-records tests/Fixtures/Messaging/masker_corpus.jsonl from the MessageMasker in this checkout (R230):
 *
 *     php tests/Support/Messaging/record_corpus.php
 *
 * Deliberate step. Run it only from a checkout whose masker is the accepted baseline (`main`), and commit
 * the fixture on its own with the reason in the message. Running it on a branch that changes the masker
 * would record the change as the baseline and defeat the monotonicity test.
 */

use Tests\Support\Messaging\MaskerCorpus;

require __DIR__.'/../../../vendor/autoload.php';

$count = MaskerCorpus::record();

fwrite(STDOUT, "Recorded {$count} corpus outputs to ".MaskerCorpus::FIXTURE.PHP_EOL);
