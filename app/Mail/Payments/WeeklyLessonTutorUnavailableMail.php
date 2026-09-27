<?php

namespace App\Mail\Payments;

use App\Enums\PaymentStatus;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Support\Mail\UsesSettingsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A weekly lesson was cancelled at charge time because its tutor was no longer bookable (R104:
 * parent and tutor). The parent's copy stays neutral about why — it never names a suspension or a
 * permit — and offers to keep or end the slot; the tutor's copy says the profile is not bookable.
 * Both say nothing was charged, but only when no payment for the lesson ever moved money —
 * `pending` (a crashed earlier attempt could have left one), `captured`, or `refunded`/
 * `partially_refunded` (R138: `succeed()`'s hold-then-refund path captures the money before giving
 * it back, so that payment was not "nothing"; the email must not claim otherwise). Only a `failed`
 * attempt, or no attempt at all, counts as nothing charged.
 */
class WeeklyLessonTutorUnavailableMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesSettingsSender;

    public function __construct(public Lesson $lesson, public User $recipient) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settingsFromAddress(),
            replyTo: array_filter([$this->settingsReplyToAddress()]),
            subject: 'A weekly lesson was cancelled',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payments.tutor-unavailable',
            with: [
                'isParent' => $this->lesson->learner->account_user_id === $this->recipient->id,
                'nothingCharged' => ! Payment::query()
                    ->where('lesson_id', $this->lesson->id)
                    ->whereIn('status', [
                        PaymentStatus::Pending,
                        PaymentStatus::Captured,
                        PaymentStatus::Refunded,
                        PaymentStatus::PartiallyRefunded,
                    ])
                    ->exists(),
            ],
        );
    }
}
