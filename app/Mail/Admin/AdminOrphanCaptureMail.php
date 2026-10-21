<?php

namespace App\Mail\Admin;

use App\Support\Mail\UsesSettingsSender;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * R259 (4): sent to admins when the gateway took money for a PaymentIntent the platform has no payment
 * for. Names the gateway reference and amount only (no payer detail: the recipient can be an external inbox).
 */
class AdminOrphanCaptureMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public string $gateway, public string $intentId, public int $amount, public string $currency) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            subject: 'A payment was taken that the platform has no record of',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin.orphan_capture', with: ['formatted' => Money::fils($this->amount)->format($this->currency)]);
    }
}
