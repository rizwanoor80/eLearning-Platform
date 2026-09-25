<?php

namespace App\Events\Lessons;

use App\Models\Lesson;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A weekly lesson's charge failed and a retry is scheduled (R101/R102). Fired after commit by
 * `ChargeReservedLesson`; the last failure is not this event — it is the lesson's
 * `reserved -> cancelled_payment_failed` transition, which `LessonStatusChanged` announces.
 */
class LessonChargeFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Lesson $lesson,
        public readonly CarbonImmutable $retryAt,
    ) {}
}
