<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;

/**
 * One bookable hour, expressed in the timezone the caller asked for.
 */
final readonly class Slot
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}
}
