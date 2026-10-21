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
 * the money by. Unique per intent for a day, so Stripe's redeliveries and the sweep's retries raise one alert.
 */
class CheckOrphanCapture implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How long the booking request is given to record its payment before the capture is called an orphan. */
    public const WAIT_MINUTES = 15;

    public int $uniqueFor = 86400;

    public function __construct(
        public readonly string $gateway,
        public readonly string $intentId,
        public readonly int $amount,
        public readonly string $currency,
    ) {}

    public function uniqueId(): string
    {
        return $this->gateway.':'.$this->intentId;
    }

    public function handle(): void
    {
        $known = Payment::query()->where('gateway', $this->gateway)->where('gateway_ref', $this->intentId)->exists();

        if (! $known) {
            OrphanCaptureDetected::dispatch($this->gateway, $this->intentId, $this->amount, $this->currency);
        }
    }
}
