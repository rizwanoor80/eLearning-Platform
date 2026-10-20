<?php

namespace App\Services\Tutors;

use App\Support\Money;

/**
 * A tutor's money in the five buckets (CLAUDE.md: never a single stored number). Plain values from
 * `TutorEarnings`; the page and the tests read them, nothing writes them.
 */
final readonly class EarningsBuckets
{
    public function __construct(
        public Money $pending,
        public Money $onHold,
        public Money $available,
        public Money $processing,
        public Money $paidToDate,
    ) {}

    /**
     * The five added up: everything the tutor has earned so far, paid or not.
     */
    public function total(): Money
    {
        return $this->pending->add($this->onHold)->add($this->available)->add($this->processing)->add($this->paidToDate);
    }
}
