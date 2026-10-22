<?php

namespace App\Services\Ops;

use App\Enums\PreflightStatus;

/**
 * One line of `ops:preflight`. It has nowhere to put a value: `$name` and `$note` are fixed strings written in
 * `Preflight`, never built from config, a row or an exception message.
 */
final readonly class PreflightResult
{
    public function __construct(public string $name, public PreflightStatus $status, public string $note = '') {}

    public function passed(): bool
    {
        return $this->status->passed();
    }
}
