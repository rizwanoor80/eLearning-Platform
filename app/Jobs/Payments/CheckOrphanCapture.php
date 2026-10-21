<?php

namespace App\Jobs\Payments;

use App\Events\Payments\OrphanCaptureDetected;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * R259 (4): a `payment_intent.succeeded` webhook for an intent that no `payments` row carries. The booking
 * request records its payment before it captures, so a legitimate capture has a row within seconds (or, if
 * the request died between the capture and the save, the sweep finds it); this job waits for that, then
 * looks once more, and only tells admins. It never refunds: with no row there is no lesson to judge
 * the money by. While a job is queued or running it is unique per intent, so redeliveries that arrive in that time raise one alert.
 */
class CheckOrphanCapture implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How long the booking request is given to record its payment before the capture is called an orphan. */
    public const WAIT_MINUTES = 95;

    public int $uniqueFor = 86400;

    public function __construct(
        public readonly string $gateway,
        public readonly string $intentId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?int $lessonId = null,
        public readonly ?int $attempt = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->gateway.':'.$this->intentId;
    }

    public function handle(): void
    {
        if (! $this->known()) {
            OrphanCaptureDetected::dispatch($this->gateway, $this->intentId, $this->amount, $this->currency);
        }
    }

    /**
     * A payment of ours that carries the intent, or is still waiting for the sweep to link it: a checkout
     * payment that kept the intent in `raw_response` (its reference is null until the sweep settles it),
     * or the recurring attempt the intent's metadata names. The wait covers the hourly sweep's window.
     */
    public function known(): bool
    {
        $byRef = Payment::query()->where('gateway', $this->gateway)->where('gateway_ref', $this->intentId);

        if ($byRef->exists()) {
            return true;
        }

        $kept = Payment::query()
            ->where('gateway', $this->gateway)
            ->whereNull('gateway_ref')
            ->whereRaw("raw_response::jsonb->>'checkout_intent' = ?", [$this->intentId])
            ->exists();

        if ($kept) {
            return true;
        }

        return $this->lessonId !== null && $this->attempt !== null
            && Payment::query()->where('gateway', $this->gateway)->where('lesson_id', $this->lessonId)->where('attempt_no', $this->attempt)->exists();
    }
}
