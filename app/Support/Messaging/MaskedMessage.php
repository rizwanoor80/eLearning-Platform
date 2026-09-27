<?php

namespace App\Support\Messaging;

/**
 * What `MessageMasker` returns: the text to store, and whether anything in it was hidden.
 */
final readonly class MaskedMessage
{
    public function __construct(
        public string $text,
        public bool $masked,
    ) {}
}
