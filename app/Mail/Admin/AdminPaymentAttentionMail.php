<?php

namespace App\Mail\Admin;

use App\Models\Payment;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 23b (R247): sent to admins when the payment gateway reports a refund or a dispute the platform did
 * not make. It names the payment and lesson ids and the amount only — no payer name, email or card
 * detail — because `AdminRecipients::resolve()` can be an external support inbox.
 */
class AdminPaymentAttentionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public Payment $payment, public string $reason) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            subject: 'A payment needs your attention',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin.payment_attention');
    }
}
