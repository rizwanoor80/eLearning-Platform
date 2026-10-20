<?php

namespace App\Services\Tutors;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A tutor's money in the five buckets (CLAUDE.md: never a single stored number). Plain values from
 * `TutorEarnings`; the page and the tests read them, nothing writes them. `pendingAvailableAt` (R258) is when the
 * earliest released-but-disputable money in `pending` moves to `available`, or null when there is none.
 */
final readonly class EarningsBuckets
{
    public function __construct(
        public Money $pending,
        public Money $onHold,
        public Money $available,
        public Money $processing,
        public Money $paidToDate,
        public ?CarbonImmutable $pendingAvailableAt = null,
    ) {}

    /**
     * The five added up: everything the tutor has earned so far, paid or not.
     */
    public function total(): Money
    {
        return $this->pending->add($this->onHold)->add($this->available)->add($this->processing)->add($this->paidToDate);
    }
}
